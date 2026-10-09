<?php
/** Styled missing-resource message inside the caller's existing shell. */
?>
<section class="notfound" aria-labelledby="notfound-title">
  <p class="notfound__code">404 / Not found</p>
  <h1 id="notfound-title"><?= e($notFoundTitle) ?></h1>
  <p><?= e($notFoundMessage) ?></p>
  <a class="notfound__back" href="<?= e($notFoundBackUrl) ?>"><?= e($notFoundBackLabel) ?> <span aria-hidden="true">&rarr;</span></a>
</section>
