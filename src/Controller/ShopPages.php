<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Controller;

use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Twig\Environment;

/**
 * What the pages a customer sees have in common: who is signed in, how to send
 * them to the shop's login and back, CSRF, and rendering.
 *
 * @internal
 */
final readonly class ShopPages
{
    use TargetPathTrait;

    public function __construct(
        private Security $security,
        private Environment $twig,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
        private LocaleContextInterface $locales,
        private string $firewall = 'shop',
    ) {}

    public function customer(): ?ShopUserInterface
    {
        $user = $this->security->getUser();

        return $user instanceof ShopUserInterface && null !== $user->getId() ? $user : null;
    }

    /** Sends the customer to the shop's login page and, after it, back to where they were. */
    public function login(Request $request): RedirectResponse
    {
        if ($request->hasSession()) {
            $this->saveTargetPath($request->getSession(), $this->firewall, $request->getUri());
        }

        return new RedirectResponse($this->urls->generate('sylius_shop_login', ['_locale' => $this->locales->getLocaleCode()]));
    }

    /** @param array<string, mixed> $context */
    public function render(string $template, array $context = [], int $status = 200): Response
    {
        $response = new Response($this->twig->render($template, $context), $status);
        // Consent pages must not be framed (clickjacking an "Allow" button) or cached.
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    public function csrfValid(Request $request, string $id): bool
    {
        $token = $request->request->get('_csrf_token');

        return \is_string($token) && $this->csrf->isTokenValid(new CsrfToken($id, $token));
    }

    /** @param array<string, string> $parameters */
    public function url(string $route, array $parameters = []): string
    {
        return $this->urls->generate($route, $parameters);
    }
}
