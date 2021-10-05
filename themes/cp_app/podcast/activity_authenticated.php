<?= $this->extend('podcast/_layout_authenticated') ?>

<?= $this->section('meta-tags') ?>
<link type="application/rss+xml" rel="alternate" title="<?= $podcast->title ?>" href="<?= $podcast->feed_url ?>"/>

<title><?= $podcast->title ?></title>
<meta name="description" content="<?= htmlspecialchars(
    $podcast->description,
) ?>" />
<link rel="shortcut icon" type="image/png" href="/favicon.ico" />
<link rel="canonical" href="<?= current_url() ?>" />
<meta property="og:title" content="<?= $podcast->title ?>" />
<meta property="og:description" content="<?= $podcast->description ?>" />
<meta property="og:locale" content="<?= $podcast->language_code ?>" />
<meta property="og:site_name" content="<?= $podcast->title ?>" />
<meta property="og:url" content="<?= current_url() ?>" />
<meta property="og:image" content="<?= $podcast->image->large_url ?>" />
<meta property="og:image:width" content="<?= config('Images')
    ->largeSize ?>" />
<meta property="og:image:height" content="<?= config('Images')
    ->largeSize ?>" />
<meta name="twitter:card" content="summary_large_image" />

<?= service('vite')
    ->asset('styles/index.css', 'css') ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?= $this->include('podcast/_navigation') ?>

<section class="max-w-2xl px-6 py-8 mx-auto">
<form action="<?= route_to('post-attempt-create', interact_as_actor()->username) ?>" method="POST" class="flex p-4 bg-white shadow rounded-xl">
    <?= csrf_field() ?>

    <?= view('_message_block') ?>

    <img src="<?= interact_as_actor()
        ->avatar_image_url ?>" alt="<?= interact_as_actor()
        ->display_name ?>" class="w-12 h-12 mr-4 rounded-full" />
    <div class="flex flex-col flex-1 min-w-0 gap-y-2">
        <Forms.Textarea
            name="message"
            required="true"
            placeholder="<?= lang('Post.form.message_placeholder') ?>"
            rows="2" />
        <Forms.Input
            name="episode_url"
            type="url"
            placeholder="<?= lang('Post.form.episode_url_placeholder') . ' (' . lang('Common.optional') . ')' ?>" />
        <Button variant="primary" size="small" type="submit" class="self-end"><?= lang('Post.form.submit') ?></Button>
    </div>
</form>
<hr class="my-4 border-2 border-pine-100">

<div class="space-y-8">
<?php foreach ($posts as $post): ?>
    <?php if ($post->reblog_of_id !== null): ?>
        <?= view('podcast/_partials/reblog_authenticated', [
    'post' => $post->reblog_of_post,
            'podcast' => $podcast,
]) ?>
    <?php else: ?>
        <?= view('podcast/_partials/post_authenticated', [
    'post' => $post,
            'podcast' => $podcast,
]) ?>
    <?php endif; ?>
<?php endforeach; ?>
</div>
</section>

<?= $this->endSection() ?>
