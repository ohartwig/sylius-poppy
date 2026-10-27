<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Admin;

use Koh\Poppy\Storage\Dbal\DbalFeedbackSink;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * The admin list of feedback reports: tasks Personal Agents could not do here.
 *
 * The text comes from agents, unchecked and possibly written to mislead. The
 * template escapes it like any input, and nothing here passes it on.
 */
final readonly class FeedbackController
{
    public function __construct(
        private DbalFeedbackSink $feedback,
        private Environment $twig,
    ) {
    }

    public function __invoke(): Response
    {
        return new Response($this->twig->render('@KohSyliusPoppy/admin/feedback.html.twig', [
            'reports' => $this->feedback->latest(200),
        ]));
    }
}
