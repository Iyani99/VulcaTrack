<form class="filterbar report-period" method="get" action="<?= e($periodAction) ?>" aria-label="Sales period">
  <label>Period
    <select name="period" class="report-period__select">
      <?php foreach (\VulcaTrack\Support\ReportPeriod::LABELS as $value => $label): ?>
        <?php if ($value === 'today' && empty($includeToday)): continue; endif; ?>
        <option value="<?= e($value) ?>"<?= $period['period'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="report-period__month">Month
    <select name="month">
      <?php foreach ($monthOptions as $monthValue): ?>
        <option value="<?= e($monthValue) ?>"<?= ($period['month'] ?? substr($today, 0, 7)) === $monthValue ? ' selected' : '' ?>><?= e((new DateTimeImmutable($monthValue . '-01'))->format('M Y')) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button type="submit">Apply</button>
</form>
