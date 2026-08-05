<?php

/**
 * The footer under every admin list: rows-per-page + total on the left,
 * Prev / Page X of Y / Next on the right.
 *
 * One partial rather than the same block pasted into nine views. It was already
 * nine identical copies differing only in a noun; adding the rows-per-page select
 * to each would have made that nine copies of a `<select>` and its inline
 * navigation too.
 *
 * "Rows per page" belongs here, next to the page numbers it governs, rather than
 * in the top filter form: it is not a filter, and as a filter field it only took
 * effect on Search.
 *
 * @var int      $total      rows matching the current filters
 * @var int      $page       current page, 1-based
 * @var int      $totalPages
 * @var int      $limit      current rows-per-page
 * @var callable $pageUrl    fn (int $page): string — preserves the other params
 * @var string   $noun       singular noun for the count, e.g. 'user'
 */
$options = [25, 50, 100];

// A limit that is not offered (hand-typed in the URL) still has to appear, or the
// select would silently show the wrong value.
if (! in_array($limit, $options, true)) {
    $options[] = $limit;
    sort($options);
}
?>
<div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-[#787774] dark:text-gray-400">

    <div class="flex items-center gap-2">
        <label for="limit" class="whitespace-nowrap"><?= esc(lang('common.rows_per_page')) ?></label>

        <?php /*
          Self-navigating, so it needs no form and no hidden inputs: the URL API
          copies every current parameter (filters, sort, order) and changes only
          the two that matter. Resetting to page 1 is deliberate — page 7 of a
          25-row listing usually does not exist once the size becomes 100.
        */ ?>
        <select id="limit"
                class="form-select w-auto py-1.5 text-sm"
                onchange="const u = new URL(window.location); u.searchParams.set('limit', this.value); u.searchParams.set('page', '1'); window.location = u;">
            <?php foreach ($options as $opt) : ?>
                <option value="<?= esc((string) $opt, 'attr') ?>" <?= $limit === $opt ? 'selected' : '' ?>><?= esc((string) $opt) ?></option>
            <?php endforeach ?>
        </select>

        <span class="whitespace-nowrap">
            <?= esc((string) $total) ?> <?= esc($noun) ?><?= $total === 1 ? '' : 's' ?> <?= esc(lang('common.total')) ?>
        </span>
    </div>

    <div class="flex items-center gap-2">
        <?php if ($page > 1) : ?>
            <a href="<?= esc($pageUrl($page - 1), 'attr') ?>" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700"><?= esc(lang('common.prev')) ?></a>
        <?php endif ?>

        <span class="whitespace-nowrap"><?= esc(lang('common.page_of')) ?> <?= esc((string) $page) ?> <?= esc(lang('common.of')) ?> <?= esc((string) $totalPages) ?></span>

        <?php if ($page < $totalPages) : ?>
            <a href="<?= esc($pageUrl($page + 1), 'attr') ?>" class="rounded-md border border-[#EAEAEA] px-3 py-1.5 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700"><?= esc(lang('common.next')) ?></a>
        <?php endif ?>
    </div>
</div>
