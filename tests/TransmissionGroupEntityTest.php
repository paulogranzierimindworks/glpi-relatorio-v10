<?php

/**
 * -------------------------------------------------------------------------
 * Relatorio Comercial plugin for GLPI
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Relatorio Comercial plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Relatorioglpicomercial\Tests;

use GlpiPlugin\Relatorioglpicomercial\TransmissionGroupEntity;
use PHPUnit\Framework\TestCase;

class TransmissionGroupEntityTest extends TestCase
{
    public function testSplitEmailsAcceptsCommaSemicolonSpaceAndNewline(): void
    {
        $raw = "fulano@cliente.com, gestor@cliente.com;\n outro@cliente.com  terceiro@cliente.com";

        $this->assertSame(
            ['fulano@cliente.com', 'gestor@cliente.com', 'outro@cliente.com', 'terceiro@cliente.com'],
            TransmissionGroupEntity::splitEmails($raw)
        );
    }

    public function testSplitEmailsIgnoresBlankSegments(): void
    {
        $this->assertSame([], TransmissionGroupEntity::splitEmails('  ,  ; '));
    }

    public function testResolveRecipientsDeduplicatesCaseInsensitively(): void
    {
        $result = TransmissionGroupEntity::resolveRecipients([
            'emails' => 'Fulano@Cliente.com, fulano@cliente.com, gestor@cliente.com',
        ]);

        $this->assertSame(['Fulano@Cliente.com', 'gestor@cliente.com'], $result['emails']);
        $this->assertSame([], $result['skipped']);
    }

    public function testResolveRecipientsReportsInvalidAddressesInsteadOfSilentlyDroppingThem(): void
    {
        $result = TransmissionGroupEntity::resolveRecipients([
            'emails' => 'valido@cliente.com, nao-e-email, outro-invalido@',
        ]);

        $this->assertSame(['valido@cliente.com'], $result['emails']);
        $this->assertCount(2, $result['skipped']);
    }

    public function testResolveRecipientsIgnoresGlpiGroupWhenNoneIsSelected(): void
    {
        $result = TransmissionGroupEntity::resolveRecipients([
            'emails'    => 'valido@cliente.com',
            'groups_id' => 0,
        ]);

        $this->assertSame(['valido@cliente.com'], $result['emails']);
        $this->assertSame([], $result['skipped']);
    }

    public function testResolveRecipientsIsEmptyForBlankInput(): void
    {
        $result = TransmissionGroupEntity::resolveRecipients(['emails' => '']);

        $this->assertSame([], $result['emails']);
        $this->assertSame([], $result['skipped']);
    }
}
