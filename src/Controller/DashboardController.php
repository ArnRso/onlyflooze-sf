<?php

namespace App\Controller;

use App\Service\Dashboard\DashboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(Request $request, DashboardService $dashboardService): Response
    {
        $month = \DateTimeImmutable::createFromFormat('!Y-m', (string) $request->query->get('month'))
            ?: new \DateTimeImmutable('first day of this month');

        return $this->render('dashboard/index.html.twig', [
            'overview' => $dashboardService->getMonthOverview($month),
        ]);
    }
}
