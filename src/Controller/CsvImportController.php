<?php

namespace App\Controller;

use App\Entity\CsvImportProfile;
use App\Entity\CsvImportSession;
use App\Entity\User;
use App\Form\CsvFileUploadType;
use App\Form\CsvImportProfileType;
use App\Form\CsvUploadType;
use App\Repository\CsvImportSessionRepository;
use App\Security\Voter\CsvImportProfileVoter;
use App\Service\CsvParserService;
use App\Service\CsvProfileService;
use App\Service\TransactionImportService;
use Exception;
use JsonException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/csv-import')]
#[IsGranted('ROLE_USER')]
class CsvImportController extends AbstractController
{
    public function __construct(
        private readonly CsvProfileService          $csvProfileService,
        private readonly TransactionImportService   $transactionImportService,
        private readonly CsvImportSessionRepository $csvImportSessionRepository,
        private readonly CsvParserService           $csvParserService
    )
    {
    }

    #[Route('/', name: 'app_csv_import_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $profiles = $this->csvProfileService->getUserProfiles($user);
        $recentSessions = $this->csvImportSessionRepository->findRecentByUser($user, 5);
        $stats = $this->csvImportSessionRepository->getImportStats($user);

        return $this->render('csv_import/index.html.twig', [
            'profiles' => $profiles,
            'recent_sessions' => $recentSessions,
            'stats' => $stats,
        ]);
    }

    #[Route('/wizard', name: 'app_csv_import_wizard', methods: ['GET', 'POST'])]
    public function wizard(Request $request): Response
    {
        $form = $this->createForm(CsvFileUploadType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            if (!is_array($data) || !isset($data['file'])) {
                throw new RuntimeException('Invalid form data');
            }
            /** @var UploadedFile $file */
            $file = $data['file'];

            // Move uploaded file to temp directory
            $uploadsDirectory = $this->getParameter('kernel.project_dir') . '/var/uploads';
            if (!is_dir($uploadsDirectory) && !mkdir($uploadsDirectory, 0755, true) && !is_dir($uploadsDirectory)) {
                throw new RuntimeException('Unable to create upload directory');
            }

            // Preserve original extension or use csv as fallback
            $originalExtension = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
            if (empty($originalExtension)) {
                $originalExtension = $file->guessExtension() ?: 'csv';
            }

            $filename = uniqid('', true) . '.' . $originalExtension;
            $file->move($uploadsDirectory, $filename);
            $filePath = $uploadsDirectory . '/' . $filename;

            // Store file path in session for configuration
            $request->getSession()->set('csv_wizard_file', $filePath);
            $request->getSession()->set('csv_wizard_filename', $file->getClientOriginalName());

            return $this->redirectToRoute('app_csv_import_configure', ['step' => 'analyze']);
        }

        return $this->render('csv_import/wizard_upload.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/configure/{step}', name: 'app_csv_import_configure', methods: ['GET', 'POST'])]
    public function configure(Request $request, string $step = 'analyze'): Response
    {
        $filePath = $request->getSession()->get('csv_wizard_file');
        $filename = $request->getSession()->get('csv_wizard_filename');

        if (!$filePath || !file_exists($filePath)) {
            $this->addFlash('error', 'Aucun fichier en cours d\'analyse. Recommencez l\'upload.');
            return $this->redirectToRoute('app_csv_import_wizard');
        }

        /** @var User $user */
        $user = $this->getUser();

        // Handle preview step
        if ($step === 'preview') {
            $profileId = $request->getSession()->get('csv_wizard_profile');
            if (!$profileId) {
                $this->addFlash('error', 'Aucun profil sélectionné.');
                return $this->redirectToRoute('app_csv_import_configure', ['step' => 'analyze']);
            }

            $profile = $this->csvProfileService->getUserProfileById($user, (string)$profileId);
            if (!$profile) {
                $this->addFlash('error', 'Profil introuvable.');
                return $this->redirectToRoute('app_csv_import_configure', ['step' => 'analyze']);
            }

            // Generate preview
            $preview = $this->transactionImportService->previewCsvData($filePath, $profile);

            if ($request->isMethod('POST')) {
                $action = $request->request->get('action');

                if ($action === 'confirm') {
                    // Proceed with import
                    try {
                        $session = $this->transactionImportService->importTransactionsFromCsv($filePath, $profile, $user);

                        // Clean up session
                        $request->getSession()->remove('csv_wizard_file');
                        $request->getSession()->remove('csv_wizard_filename');
                        $request->getSession()->remove('csv_wizard_profile');

                        // Clean up file
                        unlink($filePath);

                        $this->addFlash('success', sprintf(
                            'Import terminé ! %d transactions importées, %d doublons ignorés, %d erreurs.',
                            $session->getSuccessfulImports(),
                            $session->getDuplicates(),
                            $session->getErrors()
                        ));

                        return $this->redirectToRoute('app_csv_import_session_show', ['id' => $session->getId()]);
                    } catch (Exception $e) {
                        $this->addFlash('error', 'Erreur lors de l\'import : ' . $e->getMessage());
                    }
                } elseif ($action === 'back') {
                    return $this->redirectToRoute('app_csv_import_configure', ['step' => 'analyze']);
                }
            }

            return $this->render('csv_import/wizard_preview.html.twig', [
                'filename' => $filename,
                'profile' => $profile,
                'preview' => $preview,
            ]);
        }

        // Handle analyze step (default)
        $existingProfiles = $this->csvProfileService->getUserProfiles($user);
        $analyses = $this->analyzeFileWithDifferentSettings($filePath);

        if ($request->isMethod('POST')) {
            $postData = $request->request->all();

            if (isset($postData['use_existing_profile'])) {
                // Utiliser un profil existant
                $profileId = $postData['profile_id'];
                $profileIdString = is_string($profileId) ? $profileId : '';
                $profile = $this->csvProfileService->getUserProfileById($user, $profileIdString);

                if ($profile) {
                    $request->getSession()->set('csv_wizard_profile', $profileId);
                    return $this->redirectToRoute('app_csv_import_configure', ['step' => 'preview']);
                }
            } else {
                // Créer un nouveau profil
                $profile = new CsvImportProfile();
                $profileName = $postData['profile_name'] ?? 'Nouveau profil';
                $profileDescription = $postData['profile_description'] ?? '';
                $delimiter = $postData['delimiter'] ?? ',';
                $encoding = $postData['encoding'] ?? 'UTF-8';
                $dateFormat = $postData['date_format'] ?? 'd/m/Y';

                $profile->setName(is_string($profileName) ? $profileName : 'Nouveau profil');
                $profile->setDescription(is_string($profileDescription) ? $profileDescription : '');
                $profile->setDelimiter(is_string($delimiter) ? $delimiter : ',');
                $profile->setEncoding(is_string($encoding) ? $encoding : 'UTF-8');
                $profile->setDateFormat(is_string($dateFormat) ? $dateFormat : 'd/m/Y');
                $amountType = $postData['amount_type'] ?? 'single';
                $hasHeader = $postData['has_header'] ?? false;

                $profile->setAmountType(is_string($amountType) ? $amountType : 'single');
                $profile->setHasHeader(is_bool($hasHeader) ? $hasHeader : false);

                // Configuration du mapping
                $columnMapping = [
                    'date' => (int)$postData['date_column'],
                    'label' => (int)$postData['label_column'],
                ];

                if ($postData['amount_type'] === 'single') {
                    $columnMapping['amount'] = (int)$postData['amount_column'];
                } else {
                    $columnMapping['credit'] = (int)$postData['credit_column'];
                    $columnMapping['debit'] = (int)$postData['debit_column'];
                }

                $profile->setColumnMapping($columnMapping);

                // Sauvegarder le profil
                $this->csvProfileService->createProfile($profile, $user);

                $request->getSession()->set('csv_wizard_profile', $profile->getId()?->toString());
                return $this->redirectToRoute('app_csv_import_configure', ['step' => 'preview']);
            }
        }

        return $this->render('csv_import/wizard_configure.html.twig', [
            'step' => $step,
            'filename' => $filename,
            'analyses' => $analyses,
            'existing_profiles' => $existingProfiles,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function analyzeFileWithDifferentSettings(string $filePath): array
    {
        $analyses = [];
        $delimiters = [',', ';', "\t"];
        $encodings = ['UTF-8', 'ISO-8859-1', 'Windows-1252'];

        foreach ($delimiters as $delimiter) {
            foreach ($encodings as $encoding) {
                try {
                    $analysis = $this->csvParserService->analyzeFile($filePath, $delimiter, $encoding);
                    $analyses[] = [
                        'delimiter' => $delimiter,
                        'delimiter_name' => $delimiter === ',' ? 'Virgule' : ($delimiter === ';' ? 'Point-virgule' : 'Tabulation'),
                        'encoding' => $encoding,
                        'analysis' => $analysis,
                        'score' => $this->calculateAnalysisScore($analysis)
                    ];
                } catch (Exception) {
                    // Ignorer les erreurs d'analyse
                }
            }
        }

        // Trier par score décroissant
        usort($analyses, static fn($a, $b) => $b['score'] <=> $a['score']);

        return $analyses;
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function calculateAnalysisScore(array $analysis): int
    {
        $score = 0;

        // Plus de lignes = mieux
        $score += (int)min($analysis['total_rows'] * 10, 100);

        // Colonnes cohérentes = mieux
        if ($analysis['consistent_columns']) {
            $score += 50;
        }

        // Nombre de colonnes raisonnable
        if ($analysis['column_count'] >= 3 && $analysis['column_count'] <= 10) {
            $score += 30;
        }

        return $score;
    }

    /**
     * @throws JsonException
     */
    #[Route('/api/preview-with-settings', name: 'app_csv_import_api_preview', methods: ['POST'])]
    public function apiPreviewWithSettings(Request $request): JsonResponse
    {
        $filePath = $request->getSession()->get('csv_wizard_file');

        if (!$filePath || !file_exists($filePath)) {
            return new JsonResponse(['error' => 'Fichier non trouvé'], 400);
        }

        $settings = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // Créer un profil temporaire pour la preview
        $tempProfile = new CsvImportProfile();
        $tempProfile->setDelimiter($settings['delimiter'] ?? ',');
        $tempProfile->setEncoding($settings['encoding'] ?? 'UTF-8');
        $tempProfile->setDateFormat($settings['dateFormat'] ?? 'd/m/Y');
        $tempProfile->setAmountType($settings['amountType'] ?? 'single');
        $tempProfile->setHasHeader($settings['hasHeader'] ?? false);
        $tempProfile->setColumnMapping($settings['columnMapping'] ?? []);

        try {
            $preview = $this->transactionImportService->previewCsvData($filePath, $tempProfile);
            return new JsonResponse($preview);
        } catch (Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/profiles', name: 'app_csv_import_profiles', methods: ['GET'])]
    public function profiles(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $profiles = $this->csvProfileService->getUserProfiles($user);

        return $this->render('csv_import/profiles.html.twig', [
            'profiles' => $profiles,
        ]);
    }

    #[Route('/profiles/new', name: 'app_csv_import_profile_new', methods: ['GET', 'POST'])]
    public function newProfile(Request $request): Response
    {
        $profile = new CsvImportProfile();
        $form = $this->createForm(CsvImportProfileType::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $user */
            $user = $this->getUser();
            $this->csvProfileService->createProfile($profile, $user);

            $this->addFlash('success', 'Profil CSV créé avec succès.');

            return $this->redirectToRoute('app_csv_import_profiles');
        }

        return $this->render('csv_import/profile_new.html.twig', [
            'profile' => $profile,
            'form' => $form,
        ]);
    }

    #[Route('/profiles/{id}', name: 'app_csv_import_profile_show', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET'])]
    public function showProfile(CsvImportProfile $profile): Response
    {
        $this->denyAccessUnlessGranted(CsvImportProfileVoter::VIEW, $profile);

        return $this->render('csv_import/profile_show.html.twig', [
            'profile' => $profile,
        ]);
    }

    #[Route('/profiles/{id}/edit', name: 'app_csv_import_profile_edit', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET', 'POST'])]
    public function editProfile(Request $request, CsvImportProfile $profile): Response
    {
        $this->denyAccessUnlessGranted(CsvImportProfileVoter::EDIT, $profile);

        $form = $this->createForm(CsvImportProfileType::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->csvProfileService->updateProfile($profile);

            $this->addFlash('success', 'Profil CSV modifié avec succès.');

            return $this->redirectToRoute('app_csv_import_profiles');
        }

        return $this->render('csv_import/profile_edit.html.twig', [
            'profile' => $profile,
            'form' => $form,
        ]);
    }

    #[Route('/profiles/{id}', name: 'app_csv_import_profile_delete', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['POST'])]
    public function deleteProfile(Request $request, CsvImportProfile $profile): Response
    {
        $this->denyAccessUnlessGranted(CsvImportProfileVoter::DELETE, $profile);

        if ($this->isCsrfTokenValid('delete' . $profile->getId(), $request->getPayload()->getString('_token'))) {
            $this->csvProfileService->deleteProfile($profile);
            $this->addFlash('success', 'Profil CSV supprimé avec succès.');
        }

        return $this->redirectToRoute('app_csv_import_profiles');
    }

    #[Route('/upload', name: 'app_csv_import_upload', methods: ['GET', 'POST'])]
    public function upload(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $profiles = $this->csvProfileService->getUserProfiles($user);

        if (empty($profiles)) {
            $this->addFlash('warning', 'Vous devez d\'abord créer au moins un profil CSV.');
            return $this->redirectToRoute('app_csv_import_profile_new');
        }

        // Pre-select the profile if there's only one
        $defaultData = null;
        if (count($profiles) === 1) {
            $defaultData = ['profile' => $profiles[0]];
        }

        $form = $this->createForm(CsvUploadType::class, $defaultData, [
            'profiles' => $profiles
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            if (!isset($data['file'], $data['profile'])) {
                throw new RuntimeException('Invalid form data');
            }
            /** @var UploadedFile $file */
            $file = $data['file'];
            /** @var CsvImportProfile $profile */
            $profile = $data['profile'];

            // Move uploaded file to temp directory
            $uploadsDirectory = $this->getParameter('kernel.project_dir') . '/var/uploads';
            if (!is_dir($uploadsDirectory) && !mkdir($uploadsDirectory, 0755, true) && !is_dir($uploadsDirectory)) {
                throw new RuntimeException('Unable to create upload directory');
            }

            // Preserve original extension or use csv as fallback
            $originalExtension = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
            if (empty($originalExtension)) {
                $originalExtension = $file->guessExtension() ?: 'csv';
            }

            $filename = uniqid('', true) . '.' . $originalExtension;
            $file->move($uploadsDirectory, $filename);
            $filePath = $uploadsDirectory . '/' . $filename;

            // Validate file
            $validation = $this->transactionImportService->validateCsvFile($filePath);
            if (!$validation['valid']) {
                unlink($filePath);
                foreach ($validation['errors'] as $error) {
                    $this->addFlash('error', $error);
                }
                return $this->render('csv_import/upload.html.twig', [
                    'form' => $form,
                    'profiles' => $profiles,
                ]);
            }

            // Store file path in session for preview
            $request->getSession()->set('csv_upload_file', $filePath);
            $request->getSession()->set('csv_upload_profile', $profile->getId()?->toString());

            return $this->redirectToRoute('app_csv_import_preview');
        }

        return $this->render('csv_import/upload.html.twig', [
            'form' => $form,
            'profiles' => $profiles,
        ]);
    }

    #[Route('/preview', name: 'app_csv_import_preview', methods: ['GET', 'POST'])]
    public function preview(Request $request): Response
    {
        $filePath = $request->getSession()->get('csv_upload_file');
        $profileId = $request->getSession()->get('csv_upload_profile');

        if (!$filePath || !$profileId) {
            $this->addFlash('error', 'Aucun fichier en cours d\'import.');
            return $this->redirectToRoute('app_csv_import_upload');
        }

        /** @var User $user */
        $user = $this->getUser();
        $profile = $this->csvProfileService->getUserProfileById($user, (string)$profileId);

        if (!$profile) {
            $this->addFlash('error', 'Profil CSV introuvable.');
            return $this->redirectToRoute('app_csv_import_upload');
        }

        $preview = $this->transactionImportService->previewCsvData($filePath, $profile);

        if ($request->isMethod('POST')) {
            // Confirm import
            if ($request->request->get('confirm') === '1') {
                try {
                    $session = $this->transactionImportService->importTransactionsFromCsv($filePath, $profile, $user);

                    // Clean up
                    unlink($filePath);
                    $request->getSession()->remove('csv_upload_file');
                    $request->getSession()->remove('csv_upload_profile');

                    $this->addFlash('success', sprintf(
                        'Import terminé ! %d transactions importées, %d doublons ignorés, %d erreurs.',
                        $session->getSuccessfulImports(),
                        $session->getDuplicates(),
                        $session->getErrors()
                    ));

                    return $this->redirectToRoute('app_csv_import_session_show', ['id' => $session->getId()]);
                } catch (Exception $e) {
                    $this->addFlash('error', 'Erreur lors de l\'import : ' . $e->getMessage());
                }
            } else {
                // Cancel import
                unlink($filePath);
                $request->getSession()->remove('csv_upload_file');
                $request->getSession()->remove('csv_upload_profile');

                $this->addFlash('info', 'Import annulé.');
                return $this->redirectToRoute('app_csv_import_upload');
            }
        }

        return $this->render('csv_import/preview.html.twig', [
            'profile' => $profile,
            'preview' => $preview,
            'filename' => basename($filePath),
        ]);
    }

    #[Route('/sessions', name: 'app_csv_import_sessions', methods: ['GET'])]
    public function sessions(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessions = $this->csvImportSessionRepository->findByUser($user);

        return $this->render('csv_import/sessions.html.twig', [
            'sessions' => $sessions,
        ]);
    }

    #[Route('/sessions/{id}', name: 'app_csv_import_session_show', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET'])]
    public function showSession(CsvImportSession $session): Response
    {
        if ($session->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('csv_import/session_show.html.twig', [
            'session' => $session,
        ]);
    }
}
