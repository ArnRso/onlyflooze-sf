<?php

namespace App\Controller;

use App\Entity\ImportBatch;
use App\Exception\CsvParseException;
use App\Form\CsvUploadType;
use App\Repository\ImportBatchRepository;
use App\Service\Import\TransactionImporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ImportController extends AbstractController
{
    #[Route('/import', name: 'app_import')]
    public function index(
        Request $request,
        TransactionImporter $importer,
        ImportBatchRepository $importBatchRepository,
    ): Response {
        $form = $this->createForm(CsvUploadType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $file */
            $file = $form->get('file')->getData();

            try {
                $batch = $importer->import(
                    (string) file_get_contents($file->getPathname()),
                    $file->getClientOriginalName(),
                );

                return $this->redirectToRoute('app_import_result', ['id' => $batch->getId()]);
            } catch (CsvParseException $exception) {
                $this->addFlash('danger', 'Import impossible : '.$exception->getMessage());
            }
        }

        return $this->render('import/index.html.twig', [
            'form' => $form,
            'batches' => $importBatchRepository->findRecent(),
        ]);
    }

    #[Route('/import/{id}', name: 'app_import_result')]
    public function result(ImportBatch $batch): Response
    {
        return $this->render('import/result.html.twig', [
            'batch' => $batch,
        ]);
    }
}
