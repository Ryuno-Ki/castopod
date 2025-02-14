<article class="relative flex flex-col flex-1 flex-shrink-0 w-full transition group overflow-hidden bg-elevated border-3 snap-center hover:shadow-lg focus-within:shadow-lg focus-within:ring-accent border-subtle rounded-xl min-w-[12rem] max-w-[17rem]">
    <a href="<?= route_to('episode-view', $episode->podcast->id, $episode->id) ?>" class="flex flex-col justify-end w-full h-full text-white group">
        <div class="absolute bottom-0 left-0 z-10 w-full h-full backdrop-gradient mix-blend-multiply"></div>
        <div class="w-full h-full overflow-hidden bg-header">
            <img src="<?= $episode->cover->medium_url ?>" alt="<?= esc($episode->title) ?>" class="object-cover w-full h-full transition duration-200 ease-in-out transform group-focus:scale-105 group-hover:scale-105 aspect-square" loading="lazy" />
        </div>
        <?= publication_pill($episode->published_at, $episode->publication_status, 'absolute top-0 left-0 ml-2 mt-2 text-sm'); ?>
        <div class="absolute z-20 flex flex-col items-start px-4 py-2">
            <?= episode_numbering($episode->number, $episode->season_number, 'text-xs font-semibold !no-underline px-1 bg-black/50 mr-1', true) ?>
            <span class="font-semibold leading-tight line-clamp-2"><?= esc($episode->title) ?></span>
        </div>
    </a>
    <button class="absolute top-0 right-0 z-10 p-2 mt-2 mr-2 text-white transition -translate-y-12 rounded-full opacity-0 focus:ring-accent focus:opacity-100 focus:-translate-y-0 group-hover:translate-y-0 bg-black/50 group-hover:opacity-100" id="more-dropdown-<?= $episode->id ?>" data-dropdown="button" data-dropdown-target="more-dropdown-<?= $episode->id ?>-menu" aria-haspopup="true" aria-expanded="false" title="<?= lang('Common.more') ?>"><?= icon('more') ?></button>
    <DropdownMenu id="more-dropdown-<?= $episode->id ?>-menu" labelledby="more-dropdown-<?= $episode->id ?>" offsetY="-32" items="<?= esc(json_encode([
        [
            'type' => 'link',
            'title' => lang('Episode.go_to_page'),
            'uri' => route_to('episode', esc($episode->podcast->handle), esc($episode->slug)),
        ],
        [
            'type' => 'link',
            'title' => lang('Episode.edit'),
            'uri' => route_to('episode-edit', $episode->podcast->id, $episode->id),
        ],
        [
            'type' => 'link',
            'title' => lang('Episode.embed.title'),
            'uri' => route_to('embed-add', $episode->podcast->id, $episode->id),
        ],
        [
            'type' => 'link',
            'title' => lang('Person.persons'),
            'uri' => route_to('episode-persons-manage', $episode->podcast->id, $episode->id),
        ],
        [
            'type' => 'link',
            'title' => lang('VideoClip.list.title'),
            'uri' => route_to('video-clips-list', $episode->podcast->id, $episode->id),
        ],
        [
            'type' => 'link',
            'title' => lang('Soundbite.list.title'),
            'uri' => route_to('soundbites-list', $episode->podcast->id, $episode->id),
        ],
        [
            'type' => 'separator',
        ],
        [
            'type' => 'link',
            'title' => lang('Episode.delete'),
            'uri' => route_to('episode-delete', $episode->podcast->id, $episode->id),
            'class' => 'font-semibold text-red-600',
        ],
    ])) ?>" />
</article>