<?php

/**
 * @copyright  2020 Podlibre
 * @license    https://www.gnu.org/licenses/agpl-3.0.en.html AGPL3
 * @link       https://castopod.org/
 */

namespace App\Controllers\Admin;

use CodeIgniter\HTTP\RedirectResponse;
use App\Entities\Podcast;
use CodeIgniter\Exceptions\PageNotFoundException;
use ErrorException;
use Config\Database;
use Podlibre\PodcastNamespace\ReversedTaxonomy;
use App\Entities\Episode;
use App\Entities\Image;
use App\Entities\Person;
use App\Models\CategoryModel;
use App\Models\LanguageModel;
use App\Models\PodcastModel;
use App\Models\EpisodeModel;
use App\Models\PlatformModel;
use App\Models\PersonModel;
use Config\Services;
use League\HTMLToMarkdown\HtmlConverter;

class PodcastImportController extends BaseController
{
    /**
     * @var Podcast|null
     */
    protected $podcast;

    public function _remap(string $method, string ...$params): mixed
    {
        if (count($params) === 0) {
            return $this->$method();
        }

        if (($this->podcast = (new PodcastModel())->getPodcastById((int) $params[0])) !== null) {
            return $this->$method();
        }

        throw PageNotFoundException::forPageNotFound();
    }

    public function index(): string
    {
        helper(['form', 'misc']);

        $languageOptions = (new LanguageModel())->getLanguageOptions();
        $categoryOptions = (new CategoryModel())->getCategoryOptions();

        $data = [
            'languageOptions' => $languageOptions,
            'categoryOptions' => $categoryOptions,
            'browserLang' => get_browser_language(
                $this->request->getServer('HTTP_ACCEPT_LANGUAGE'),
            ),
        ];

        return view('admin/podcast/import', $data);
    }

    public function attemptImport(): RedirectResponse
    {
        helper(['media', 'misc']);

        $rules = [
            'imported_feed_url' => 'required|validate_url',
            'season_number' => 'is_natural_no_zero|permit_empty',
            'max_episodes' => 'is_natural_no_zero|permit_empty',
        ];

        if (!$this->validate($rules)) {
            return redirect()
                ->back()
                ->withInput()
                ->with('errors', $this->validator->getErrors());
        }
        try {
            ini_set('user_agent', 'Castopod/' . CP_VERSION);
            $feed = simplexml_load_file(
                $this->request->getPost('imported_feed_url'),
            );
        } catch (ErrorException $errorException) {
            return redirect()
                ->back()
                ->withInput()
                ->with('errors', [
                    $errorException->getMessage() .
                        ': <a href="' .
                        $this->request->getPost('imported_feed_url') .
                        '" rel="noreferrer noopener" target="_blank">' .
                        $this->request->getPost('imported_feed_url') .
                        ' ⎋</a>',
                ]);
        }
        $nsItunes = $feed->channel[0]->children(
            'http://www.itunes.com/dtds/podcast-1.0.dtd',
        );
        $nsPodcast = $feed->channel[0]->children(
            'https://github.com/Podcastindex-org/podcast-namespace/blob/main/docs/1.0.md',
        );
        $nsContent = $feed->channel[0]->children(
            'http://purl.org/rss/1.0/modules/content/',
        );

        if ((string) $nsPodcast->locked === 'yes') {
            return redirect()
                ->back()
                ->withInput()
                ->with('errors', [lang('PodcastImport.lock_import')]);
        }

        $converter = new HtmlConverter();

        $channelDescriptionHtml = (string) $feed->channel[0]->description;

        try {
            if (
                $nsItunes->image !== null &&
                $nsItunes->image->attributes()['href'] !== null
            ) {
                $imageFile = download_file(
                    (string) $nsItunes->image->attributes()['href'],
                );
            } else {
                $imageFile = download_file(
                    (string) $feed->channel[0]->image->url,
                );
            }

            $podcast = new Podcast([
                'name' => $this->request->getPost('name'),
                'imported_feed_url' => $this->request->getPost(
                    'imported_feed_url',
                ),
                'new_feed_url' => base_url(
                    route_to('podcast_feed', $this->request->getPost('name')),
                ),
                'title' => (string) $feed->channel[0]->title,
                'description_markdown' => $converter->convert(
                    $channelDescriptionHtml,
                ),
                'description_html' => $channelDescriptionHtml,
                'image' => new Image($imageFile),
                'language_code' => $this->request->getPost('language'),
                'category_id' => $this->request->getPost('category'),
                'parental_advisory' =>
                $nsItunes->explicit === null
                    ? null
                    : (in_array($nsItunes->explicit, ['yes', 'true'])
                        ? 'explicit'
                        : (in_array($nsItunes->explicit, ['no', 'false'])
                            ? 'clean'
                            : null)),
                'owner_name' => (string) $nsItunes->owner->name,
                'owner_email' => (string) $nsItunes->owner->email,
                'publisher' => (string) $nsItunes->author,
                'type' =>
                $nsItunes->type === null ? 'episodic' : $nsItunes->type,
                'copyright' => (string) $feed->channel[0]->copyright,
                'is_blocked' =>
                $nsItunes->block === null
                    ? false
                    : $nsItunes->block === 'yes',
                'is_completed' =>
                $nsItunes->complete === null
                    ? false
                    : $nsItunes->complete === 'yes',
                'location_name' => $nsPodcast->location
                    ? (string) $nsPodcast->location
                    : null,
                'location_geo' =>
                !$nsPodcast->location ||
                    $nsPodcast->location->attributes()['geo'] === null
                    ? null
                    : (string) $nsPodcast->location->attributes()['geo'],
                'location_osm_id' =>
                !$nsPodcast->location ||
                    $nsPodcast->location->attributes()['osm'] === null
                    ? null
                    : (string) $nsPodcast->location->attributes()['osm'],
                'created_by' => user_id(),
                'updated_by' => user_id(),
            ]);
        } catch (ErrorException $ex) {
            return redirect()
                ->back()
                ->withInput()
                ->with('errors', [
                    $ex->getMessage() .
                        ': <a href="' .
                        $this->request->getPost('imported_feed_url') .
                        '" rel="noreferrer noopener" target="_blank">' .
                        $this->request->getPost('imported_feed_url') .
                        ' ⎋</a>',
                ]);
        }

        $podcastModel = new PodcastModel();
        $db = Database::connect();

        $db->transStart();

        if (!($newPodcastId = $podcastModel->insert($podcast, true))) {
            $db->transRollback();
            return redirect()
                ->back()
                ->withInput()
                ->with('errors', $podcastModel->errors());
        }

        $authorize = Services::authorization();
        $podcastAdminGroup = $authorize->group('podcast_admin');

        $podcastModel->addPodcastContributor(
            user_id(),
            $newPodcastId,
            $podcastAdminGroup->id,
        );

        $podcastsPlatformsData = [];
        $platformTypes = [
            ['name' => 'podcasting', 'elements' => $nsPodcast->id],
            ['name' => 'social', 'elements' => $nsPodcast->social],
            ['name' => 'funding', 'elements' => $nsPodcast->funding],
        ];
        $platformModel = new PlatformModel();
        foreach ($platformTypes as $platformType) {
            foreach ($platformType['elements'] as $platform) {
                $platformLabel = $platform->attributes()['platform'];
                $platformSlug = slugify($platformLabel);
                if ($platformModel->getPlatform($platformSlug) !== null) {
                    $podcastsPlatformsData[] = [
                        'platform_slug' => $platformSlug,
                        'podcast_id' => $newPodcastId,
                        'link_url' => $platform->attributes()['url'],
                        'link_content' => $platform->attributes()['id'],
                        'is_visible' => false,
                    ];
                }
            }
        }

        if (count($podcastsPlatformsData) > 1) {
            $platformModel->createPodcastPlatforms(
                $newPodcastId,
                $podcastsPlatformsData,
            );
        }

        foreach ($nsPodcast->person as $podcastPerson) {
            $fullName = (string) $podcastPerson;
            $personModel = new PersonModel();
            $newPersonId = null;
            if (($newPerson = $personModel->getPerson($fullName)) !== null) {
                $newPersonId = $newPerson->id;
            } else {
                $newPodcastPerson = new Person([
                    'full_name' => $fullName,
                    'unique_name' => slugify($fullName),
                    'information_url' => $podcastPerson->attributes()['href'],
                    'image' => new Image(download_file($podcastPerson->attributes()['img'])),
                    'created_by' => user_id(),
                    'updated_by' => user_id(),
                ]);

                if (!$newPersonId = $personModel->insert($newPodcastPerson)) {
                    return redirect()
                        ->back()
                        ->withInput()
                        ->with('errors', $personModel->errors());
                }
            }

            $personGroup =
                isset($podcastPerson->attributes()['group'])
                ? ['slug' => '']
                : ReversedTaxonomy::$taxonomy[(string) $podcastPerson->attributes()['group']];
            $personRole =
                isset($podcastPerson->attributes()['role']) ||
                $personGroup === null
                ? ['slug' => '']
                : $personGroup['roles'][strval($podcastPerson->attributes()['role'])];

            $podcastPersonModel = new PersonModel();
            if (!$podcastPersonModel->addPodcastPerson($newPodcastId, $newPersonId, $personGroup['slug'], $personRole['slug'])) {
                return redirect()
                    ->back()
                    ->withInput()
                    ->with('errors', $podcastPersonModel->errors());
            }
        }

        $numberItems = $feed->channel[0]->item->count();
        $lastItem =
            $this->request->getPost('max_episodes') !== null &&
            $this->request->getPost('max_episodes') < $numberItems
            ? $this->request->getPost('max_episodes')
            : $numberItems;

        $slugs = [];

        //////////////////////////////////////////////////////////////////
        // For each Episode:
        for ($itemNumber = 1; $itemNumber <= $lastItem; ++$itemNumber) {
            $item = $feed->channel[0]->item[$numberItems - $itemNumber];

            $nsItunes = $item->children(
                'http://www.itunes.com/dtds/podcast-1.0.dtd',
            );
            $nsPodcast = $item->children(
                'https://github.com/Podcastindex-org/podcast-namespace/blob/main/docs/1.0.md',
            );
            $nsContent = $item->children(
                'http://purl.org/rss/1.0/modules/content/',
            );

            $slug = slugify(
                $this->request->getPost('slug_field') === 'title'
                    ? $item->title
                    : basename($item->link),
            );
            if (in_array($slug, $slugs)) {
                $slugNumber = 2;
                while (in_array($slug . '-' . $slugNumber, $slugs)) {
                    ++$slugNumber;
                }
                $slug = $slug . '-' . $slugNumber;
            }
            $slugs[] = $slug;
            $itemDescriptionHtml = match ($this->request->getPost('description_field')) {
                'content' => $nsContent->encoded,
                'summary' => $nsItunes->summary,
                'subtitle_summary' => $nsItunes->subtitle . '<br/>' . $nsItunes->summary,
                default => $item->description,
            };

            if (
                $nsItunes->image !== null &&
                $nsItunes->image->attributes()['href'] !== null
            ) {
                $episodeImage = new Image(
                    download_file(
                        (string) $nsItunes->image->attributes()['href'],
                    ),
                );
            } else {
                $episodeImage = null;
            }

            $newEpisode = new Episode([
                'podcast_id' => $newPodcastId,
                'guid' => $item->guid ?? null,
                'title' => $item->title,
                'slug' => $slug,
                'audio_file' => download_file(
                    $item->enclosure->attributes()['url'],
                ),
                'description_markdown' => $converter->convert(
                    $itemDescriptionHtml,
                ),
                'description_html' => $itemDescriptionHtml,
                'image' => $episodeImage,
                'parental_advisory' =>
                $nsItunes->explicit === null
                    ? null
                    : (in_array($nsItunes->explicit, ['yes', 'true'])
                        ? 'explicit'
                        : (in_array($nsItunes->explicit, ['no', 'false'])
                            ? 'clean'
                            : null)),
                'number' =>
                $this->request->getPost('force_renumber') === 'yes'
                    ? $itemNumber
                    : $nsItunes->episode,
                'season_number' =>
                $this->request->getPost('season_number') === null
                    ? $nsItunes->season
                    : $this->request->getPost('season_number'),
                'type' =>
                $nsItunes->episodeType === null
                    ? 'full'
                    : $nsItunes->episodeType,
                'is_blocked' =>
                $nsItunes->block === null
                    ? false
                    : $nsItunes->block === 'yes',
                'location_name' => $nsPodcast->location
                    ? $nsPodcast->location
                    : null,
                'location_geo' =>
                !$nsPodcast->location ||
                    $nsPodcast->location->attributes()['geo'] === null
                    ? null
                    : $nsPodcast->location->attributes()['geo'],
                'location_osm_id' =>
                !$nsPodcast->location ||
                    $nsPodcast->location->attributes()['osm'] === null
                    ? null
                    : $nsPodcast->location->attributes()['osm'],
                'created_by' => user_id(),
                'updated_by' => user_id(),
                'published_at' => strtotime($item->pubDate),
            ]);

            $episodeModel = new EpisodeModel();

            if (!($newEpisodeId = $episodeModel->insert($newEpisode, true))) {
                // FIXME: What shall we do?
                return redirect()
                    ->back()
                    ->withInput()
                    ->with('errors', $episodeModel->errors());
            }

            foreach ($nsPodcast->person as $episodePerson) {
                $fullName = (string) $episodePerson;
                $personModel = new PersonModel();
                $newPersonId = null;
                if (($newPerson = $personModel->getPerson($fullName)) !== null) {
                    $newPersonId = $newPerson->id;
                } else {
                    $newEpisodePerson = new Person([
                        'full_name' => $fullName,
                        'slug' => slugify($fullName),
                        'information_url' => $episodePerson->attributes()['href'],
                        'image' => new Image(download_file($episodePerson->attributes()['img']))
                    ]);

                    if (!($newPersonId = $personModel->insert($newEpisodePerson))) {
                        return redirect()
                            ->back()
                            ->withInput()
                            ->with('errors', $personModel->errors());
                    }
                }

                $personGroup =
                    $episodePerson->attributes()['group'] === null
                    ? ['slug' => '']
                    : ReversedTaxonomy::$taxonomy[strval($episodePerson->attributes()['group'])];
                $personRole =
                    $episodePerson->attributes()['role'] === null ||
                    $personGroup === null
                    ? ['slug' => '']
                    : $personGroup['roles'][strval($episodePerson->attributes()['role'])];


                $episodePersonModel = new PersonModel();
                if (!$episodePersonModel->addEpisodePerson($newPodcastId, $newEpisodeId, $newPersonId, $personGroup['slug'], $personRole['slug'])) {
                    return redirect()
                        ->back()
                        ->withInput()
                        ->with('errors', $episodePersonModel->errors());
                }
            }
        }

        // set interact as the newly imported podcast actor
        $importedPodcast = (new PodcastModel())->getPodcastById($newPodcastId);
        set_interact_as_actor($importedPodcast->actor_id);

        $db->transComplete();

        return redirect()->route('podcast-view', [$newPodcastId]);
    }
}
