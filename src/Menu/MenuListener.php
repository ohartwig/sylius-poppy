<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

/** "Connected agents" in the customer's account menu, "Agent feedback" in the admin. */
final readonly class MenuListener
{
    public function __construct(
        private bool $account = true,
        private bool $admin = true,
    ) {
    }

    public function account(MenuBuilderEvent $event): void
    {
        if (!$this->account) {
            return;
        }

        $event->getMenu()
            ->addChild('koh_poppy_agents', ['route' => 'koh_poppy_account_agents'])
            ->setLabel('koh_poppy.account.menu')
            ->setLabelAttribute('icon', 'tabler:robot');
    }

    public function admin(MenuBuilderEvent $event): void
    {
        if (!$this->admin) {
            return;
        }

        $parent = $event->getMenu()->getChild('configuration') ?? $event->getMenu();
        $parent
            ->addChild('koh_poppy_feedback', ['route' => 'koh_poppy_admin_feedback'])
            ->setLabel('koh_poppy.admin.feedback.menu')
            ->setLabelAttribute('icon', 'tabler:message-question');
    }
}
