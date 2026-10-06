<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * DEPRECATED: Plugins and themes should stop using "ImageResizer" and start using "ImageProcessing"
 */

class_alias('ImageProcessing', 'ImageResizer');

/**
 * DEPRECATED: use mindstellar\backup\BackupException. Made at once: a catch or instanceof on the
 * old name does not autoload, so a lazy alias would never match.
 */
class_alias('mindstellar\backup\BackupException', 'mindstellar\backup\BackupFailure');

/**
 * DEPRECATED: renamed classes, old name => new name. Each alias is made only when code asks
 * for the old name, so nothing loads on a request that does not use one.
 */
const OSC_RENAMED_CLASSES = array(
    'mindstellar\\Csrf' => 'mindstellar\\security\\Csrf',
    'mindstellar\\backup\\BackupManager' => 'mindstellar\\backup\\BackupService',
    'mindstellar\\billing\\Packages' => 'mindstellar\\billing\\PackageStore',
    'mindstellar\\billing\\Orders' => 'mindstellar\\billing\\OrderStore',
    'mindstellar\\billing\\Entitlements' => 'mindstellar\\billing\\EntitlementStore',
    'mindstellar\\billing\\ItemUpgrades' => 'mindstellar\\billing\\ItemUpgradeStore',
    'mindstellar\\forms\\FormService' => 'mindstellar\\form\\builder\\FormService',
    'mindstellar\\forms\\FieldValidator' => 'mindstellar\\form\\builder\\FieldValidator',
    'mindstellar\\forms\\FormContextRegistry' => 'mindstellar\\form\\builder\\FormContextRegistry',
    'mindstellar\\admin\\form\\AdvancedSettingsForm' => 'mindstellar\\admin\\form\\AdvancedSettingsScreen',
    'mindstellar\\admin\\form\\BillingSettingsForm' => 'mindstellar\\admin\\form\\BillingSettingsScreen',
    'mindstellar\\admin\\form\\CommentSettingsForm' => 'mindstellar\\admin\\form\\CommentSettingsScreen',
    'mindstellar\\admin\\form\\KeywordBlockSettingsForm' => 'mindstellar\\admin\\form\\KeywordBlockSettingsScreen',
    'mindstellar\\admin\\form\\LatestSearchSettingsForm' => 'mindstellar\\admin\\form\\LatestSearchSettingsScreen',
    'mindstellar\\admin\\form\\MailServerSettingsForm' => 'mindstellar\\admin\\form\\MailServerSettingsScreen',
    'mindstellar\\admin\\form\\MainSettingsForm' => 'mindstellar\\admin\\form\\MainSettingsScreen',
    'mindstellar\\admin\\form\\MediaSettingsForm' => 'mindstellar\\admin\\form\\MediaSettingsScreen',
    'mindstellar\\admin\\form\\PermalinkSettingsForm' => 'mindstellar\\admin\\form\\PermalinkSettingsScreen',
    'mindstellar\\admin\\form\\SitemapSettingsForm' => 'mindstellar\\admin\\form\\SitemapSettingsScreen',
    'mindstellar\\admin\\form\\SpamSettingsForm' => 'mindstellar\\admin\\form\\SpamSettingsScreen',
    'mindstellar\\admin\\form\\StorageSettingsForm' => 'mindstellar\\admin\\form\\StorageSettingsScreen',
    'mindstellar\\api\\ApiSettings' => 'mindstellar\\apiaccess\\ApiSettings',
    'mindstellar\\api\\auth\\ApiKeys' => 'mindstellar\\apiaccess\\ApiKeys',
    'mindstellar\\api\\auth\\Credential' => 'mindstellar\\apiaccess\\Credential',
    'mindstellar\\api\\auth\\CredentialKind' => 'mindstellar\\apiaccess\\CredentialKind',
    'mindstellar\\api\\auth\\CredentialStore' => 'mindstellar\\apiaccess\\CredentialStore',
    'mindstellar\\api\\auth\\KeyOwner' => 'mindstellar\\apiaccess\\KeyOwner',
    'mindstellar\\api\\auth\\Scopes' => 'mindstellar\\apiaccess\\Scopes',
    'mindstellar\\api\\auth\\StoredKey' => 'mindstellar\\apiaccess\\StoredKey',
);

spl_autoload_register(static function (string $class): void {
    if (isset(OSC_RENAMED_CLASSES[$class])) {
        class_alias(OSC_RENAMED_CLASSES[$class], $class);
    }
});
