<?php

if (!function_exists('renderParentPortalTopbar')) {
    function renderParentPortalTopbar(string $active, bool $showTermsButton = false): void
    {
        $items = [
            'home'     => ['index.php',    'fa-house',    'Αρχική'],
            'children' => ['children.php', 'fa-children', 'Παιδιά'],
            'events'   => ['events.php',   'fa-trophy',   'Διοργανώσεις'],
            'settings' => ['settings.php', 'fa-gear',     'Ρυθμίσεις'],
        ];
        ?>
        <header class="pp-topbar">
          <a href="index.php" class="pp-logo" aria-label="MAster — Αρχική">
            <span class="logo-ma">MA</span><span class="logo-ster">ster</span>
          </a>
          <nav class="pp-nav" aria-label="Κύρια πλοήγηση">
            <?php foreach ($items as $key => [$href, $icon, $label]): ?>
              <a href="<?= $href ?>"<?= $active === $key ? ' class="active" aria-current="page"' : '' ?>>
                <i class="fas <?= $icon ?>" aria-hidden="true"></i><span class="nav-label"><?= $label ?></span>
              </a>
            <?php endforeach; ?>
            <a href="<?= APP_URL ?>/logout.php" class="nav-logout">
              <i class="fas fa-right-from-bracket" aria-hidden="true"></i><span class="nav-label">Έξοδος</span>
            </a>
          </nav>
          <?php if ($showTermsButton): ?>
            <button class="terms-fab" id="termsFab" title="Όροι Χρήσης" aria-label="Εμφάνιση Όρων Χρήσης">
              <i class="fas fa-file-lines" aria-hidden="true"></i>
            </button>
          <?php endif; ?>
        </header>
        <?php
    }

    function renderParentPortalBottomNav(string $active): void
    {
        $items = [
            'home'     => ['index.php',    'fa-house',    'Αρχική'],
            'children' => ['children.php', 'fa-children', 'Παιδιά'],
            'events'   => ['events.php',   'fa-trophy',   'Διοργανώσεις'],
            'settings' => ['settings.php', 'fa-gear',     'Ρυθμίσεις'],
        ];
        ?>
        <nav class="pp-bottom-nav" aria-label="Κύρια πλοήγηση κινητού">
          <div class="pp-bottom-nav-inner">
            <?php foreach ($items as $key => [$href, $icon, $label]): ?>
              <a href="<?= $href ?>"<?= $active === $key ? ' class="active" aria-current="page"' : '' ?>>
                <i class="fas <?= $icon ?>" aria-hidden="true"></i><span><?= $label ?></span>
              </a>
            <?php endforeach; ?>
            <a href="<?= APP_URL ?>/logout.php" class="nav-logout">
              <i class="fas fa-right-from-bracket" aria-hidden="true"></i><span>Έξοδος</span>
            </a>
          </div>
        </nav>
        <?php
    }
}
