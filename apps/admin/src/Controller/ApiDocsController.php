<?php

declare(strict_types=1);

namespace Admin\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Entry point for the API documentation (Scalar reference UI).
 *
 * The rendered docs live as static assets in public/api/ and are served
 * from the same docroot for both the public and admin hosts. This route
 * keeps the admin navigation linkable through a named route that can be
 * gated by the security firewall / auth matrix like any other /balie route.
 */
class ApiDocsController
{
    #[Route('/balie/api-docs', name: 'app_admin_api_docs', methods: ['GET'])]
    public function index(): Response
    {
        return new RedirectResponse('/api/index.html', Response::HTTP_FOUND);
    }
}