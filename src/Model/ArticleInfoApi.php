<?php

declare(strict_types = 1);

namespace App\Model;

use App\Helper\AutomatedEditsHelper;
use App\Helper\I18nHelper;
use App\Repository\ArticleInfoRepository;
use DateTime;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * An ArticleInfoApi is standalone logic for the Article Info tool. These methods perform SQL queries
 * or make API requests and can be called directly, without any knowledge of the child ArticleInfo class.
 * It does require that the ArticleInfoRepository be set, however.
 * @see ArticleInfo
 */
class ArticleInfoApi extends Model
{
    protected AutomatedEditsHelper $autoEditsHelper;
    protected I18nHelper $i18n;

    /** @var int Number of revisions that belong to the page. */
    protected int $numRevisions;

    /** @var array Prose stats, with keys 'characters', 'words', 'references', 'unique_references', 'sections'. */
    protected array $proseStats;

    /** @var array Number of categories, templates and files on the page. */
    protected array $transclusionData;

    /** @var array Various statistics about bots that edited the page. */
    protected array $bots;

    /** @var int Number of edits made to the page by bots. */
    protected int $botRevisionCount;

    /** @var int[] Number of in and outgoing links and redirects to the page. */
    protected array $linksAndRedirects;

    /** @var string[] Assessments of the page (see Page::getAssessments). */
    protected array $assessments;

    /** @var string[] List of Wikidata and Checkwiki errors. */
    protected array $bugs;

    /**
     * ArticleInfoApi constructor.
     * @param ArticleInfoRepository $repository
     * @param I18nHelper $i18n
     * @param AutomatedEditsHelper $autoEditsHelper
     * @param Page $page The page to process.
     * @param false|int $start Start date as Unix timestmap.
     * @param false|int $end End date as Unix timestamp.
     */
    public function __construct(
        ArticleInfoRepository $repository,
        I18nHelper $i18n,
        AutomatedEditsHelper $autoEditsHelper,
        Page $page,
        $start = false,
        $end = false
    ) {
        $this->repository = $repository;
        $this->i18n = $i18n;
        $this->autoEditsHelper = $autoEditsHelper;
        $this->page = $page;
        $this->start = $start;
        $this->end = $end;
    }

    /**
     * Get the number of revisions belonging to the page.
     * @return int
     */
    public function getNumRevisions(): int
    {
        if (!isset($this->numRevisions)) {
            $this->numRevisions = $this->page->getNumRevisions(null, $this->start, $this->end);
        }
        return $this->numRevisions;
    }

    /**
     * Are there more revisions than we should process, based on the config?
     * @return bool
     */
    public function tooManyRevisions(): bool
    {
        return $this->repository->getMaxPageRevisions() > 0 &&
            $this->getNumRevisions() > $this->repository->getMaxPageRevisions();
    }

    /**
     * Get various basic info used in the API, including the number of revisions, unique authors, initial author
     * and edit count of the initial author. This is combined into one query for better performance. Caching is
     * intentionally disabled, because using the gadget, this will get hit for a different page constantly, where
     * the likelihood of cache benefiting us is slim.
     * @return string[]|false false if the page was not found.
     */
    public function getBasicEditingInfo()
    {
        return $this->repository->getBasicEditingInfo($this->page);
    }

    /**
     * Get the top editors to the page by edit count.
     * @param int $limit Default 20, maximum 1,000.
     * @param bool $noBots Set to non-false to exclude bots from the result.
     * @return array
     */
    public function getTopEditorsByEditCount(int $limit = 20, bool $noBots = false): array
    {
        // Quick cache, valid only for the same request.
        static $topEditors = null;
        if (null !== $topEditors) {
            return $topEditors;
        }

        $rows = $this->repository->getTopEditorsByEditCount(
            $this->page,
            $this->start,
            $this->end,
            min($limit, 1000),
            $noBots
        );

        $topEditors = [];
        $rank = 0;
        foreach ($rows as $row) {
            $topEditors[] = [
                'rank' => ++$rank,
                'username' => $row['username'],
                'count' => $row['count'],
                'minor' => $row['minor'],
                'first_edit' => [
                    'id' => $row['first_revid'],
                    'timestamp' => $row['first_timestamp'],
                ],
                'latest_edit' => [
                    'id' => $row['latest_revid'],
                    'timestamp' => $row['latest_timestamp'],
                ],
            ];
        }

        return $topEditors;
    }

    /**
     * Get prose and reference information.
     * @return array With keys 'characters', 'words', 'references', 'unique_references'
     */
    public function getProseStats(): array
    {
        if (isset($this->proseStats)) {
            return $this->proseStats;
        }

        $datetime = is_int($this->end) ? new DateTime("@$this->end") : null;
        $html = $this->page->getHTMLContent($datetime);

        $crawler = new Crawler($html);

        [$chars, $words] = $this->countCharsAndWords($crawler, '#mw-content-text p');

        $refs = $crawler->filter('#mw-content-text .reference');
        $refContent = [];
        $refs->each(function ($ref) use (&$refContent): void {
            $refContent[] = $ref->text();
        });
        $uniqueRefs = count(array_unique($refContent));

        $sections = count($crawler->filter('#mw-content-text .mw-headline'));

        $this->proseStats = [
            'characters' => $chars,
            'words' => $words,
            'references' => $refs->count(),
            'unique_references' => $uniqueRefs,
            'sections' => $sections,
        ];
        return $this->proseStats;
    }

    /**
     * Count the number of characters and words of the plain text within the DOM element matched by the given selector.
     * @param Crawler $crawler
     * @param string $selector HTML selector.
     * @return array [num chars, num words]
     */
    private function countCharsAndWords(Crawler $crawler, string $selector): array
    {
        $totalChars = 0;
        $totalWords = 0;
        $paragraphs = $crawler->filter($selector);
        $paragraphs->each(function ($node) use (&$totalChars, &$totalWords): void {
            /** @var Crawler $node */
            $text = preg_replace('/\[\d+]/', '', trim($node->text(null, true)));
            $totalChars += strlen($text);
            $totalWords += count(explode(' ', $text));
        });

        return [$totalChars, $totalWords];
    }

    /**
     * Get the page assessments of the page.
     * @see https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:PageAssessments
     * @return string[]|null null if unsupported.
     * @codeCoverageIgnore
     */
    public function getAssessments(): ?array
    {
        if (!isset($this->assessments)) {
            $this->assessments = $this->page
                ->getProject()
                ->getPageAssessments()
                ->getAssessments($this->page);
        }
        return $this->assessments;
    }

    /**
     * Get the list of page's wikidata and Checkwiki errors.
     * @see Page::getErrors()
     * @return string[]
     */
    public function getBugs(): array
    {
        if (!isset($this->bugs)) {
            $this->bugs = $this->page->getErrors();
        }
        return $this->bugs;
    }

    /**
     * Get the number of wikidata nad CheckWiki errors.
     * @return int
     */
    public function numBugs(): int
    {
        return count($this->getBugs());
    }

    /**
     * Generate the data structure that will used in the ArticleInfo API response.
     * @param Project $project
     * @param Page $page
     * @return array
     * @codeCoverageIgnore
     */
    public function getArticleInfoApiData(Project $project, Page $page): array
    {
        /** Number of days to query for pageviews */
        $pageviewsOffset = 30;

        $data = [
            'project' => $project->getDomain(),
            'page' => $page->getTitle(),
            'watchers' => (int) $page->getWatchers(),
            'pageviews' => $page->getLastPageviews($pageviewsOffset),
            'pageviews_offset' => $pageviewsOffset,
        ];

        $info = null;

        try {
            $info = $this->repository->getBasicEditingInfo($page);
        } catch (ServiceUnavailableHttpException $e) {
            // No more open database connections.
            $data['error'] = 'Unable to fetch revision data. Please try again later.';
        } catch (HttpException $e) {
            /**
             * The query most likely exceeded the maximum query time,
             * so we'll abort and give only info retrieved by the API.
             */
            $data['error'] = 'Unable to fetch revision data. The query may have timed out.';
        }

        if ($info) {
            $creationDateTime = DateTime::createFromFormat('YmdHis', $info['created_at']);
            $modifiedDateTime = DateTime::createFromFormat('YmdHis', $info['modified_at']);
            $secsSinceLastEdit = (new DateTime)->getTimestamp() - $modifiedDateTime->getTimestamp();

            // Some wikis (such foundation.wikimedia.org) may be missing the creation date.
            $creationDateTime = false === $creationDateTime
                ? null
                : $creationDateTime->format('Y-m-d');

            $assessment = $page->getProject()
                ->getPageAssessments()
                ->getAssessment($page);

            $data = array_merge($data, [
                'revisions' => (int) $info['num_edits'],
                'editors' => (int) $info['num_editors'],
                'minor_edits' => (int) $info['minor_edits'],
                'author' => $info['author'],
                'author_editcount' => null === $info['author_editcount'] ? null : (int) $info['author_editcount'],
                'created_at' => $creationDateTime,
                'created_rev_id' => $info['created_rev_id'],
                'modified_at' => $modifiedDateTime->format('Y-m-d H:i'),
                'secs_since_last_edit' => $secsSinceLastEdit,
                'last_edit_id' => (int) $info['modified_rev_id'],
                'assessment' => $assessment,
            ]);
        }

        return $data;
    }

    /************************ Link statistics ************************/

    /**
     * Get the number of external links on the page.
     * @return int
     */
    public function linksExtCount(): int
    {
        return $this->getLinksAndRedirects()['links_ext_count'];
    }

    /**
     * Get the number of incoming links to the page.
     * @return int
     */
    public function linksInCount(): int
    {
        return $this->getLinksAndRedirects()['links_in_count'];
    }

    /**
     * Get the number of outgoing links from the page.
     * @return int
     */
    public function linksOutCount(): int
    {
        return $this->getLinksAndRedirects()['links_out_count'];
    }

    /**
     * Get the number of redirects to the page.
     * @return int
     */
    public function redirectsCount(): int
    {
        return $this->getLinksAndRedirects()['redirects_count'];
    }

    /**
     * Get the number of external, incoming and outgoing links, along with the number of redirects to the page.
     * @return int[]
     * @codeCoverageIgnore
     */
    private function getLinksAndRedirects(): array
    {
        if (!isset($this->linksAndRedirects)) {
            $this->linksAndRedirects = $this->page->countLinksAndRedirects();
        }
        return $this->linksAndRedirects;
    }

    /**
     * Fetch transclusion data (categories, templates and files) that are on the page.
     * @return array With keys 'categories', 'templates' and 'files'.
     */
    public function getTransclusionData(): array
    {
        if (!isset($this->transclusionData)) {
            $this->transclusionData = $this->repository->getTransclusionData($this->page);
        }
        return $this->transclusionData;
    }

    /**
     * Get the number of categories that are on the page.
     * @return int
     */
    public function getNumCategories(): int
    {
        return $this->getTransclusionData()['categories'];
    }

    /**
     * Get the number of templates that are on the page.
     * @return int
     */
    public function getNumTemplates(): int
    {
        return $this->getTransclusionData()['templates'];
    }

    /**
     * Get the number of files that are on the page.
     * @return int
     */
    public function getNumFiles(): int
    {
        return $this->getTransclusionData()['files'];
    }

    /************************ Bot statistics ************************/

    /**
     * Number of edits made to the page by current or former bots.
     * @param string[][] $bots Used only in unit tests, where we supply mock data for the bots that will get processed.
     * @return int
     */
    public function getBotRevisionCount(?array $bots = null): int
    {
        if (isset($this->botRevisionCount)) {
            return $this->botRevisionCount;
        }

        if (null === $bots) {
            $bots = $this->getBots();
        }

        $count = 0;

        foreach (array_values($bots) as $data) {
            $count += $data['count'];
        }

        $this->botRevisionCount = $count;
        return $count;
    }

    /**
     * Get and set $this->bots about bots that edited the page. This is done separately from the main query because
     * we use this information when computing the top 10 editors in ArticleInfo, where we don't want to include bots.
     * @return array
     */
    public function getBots(): array
    {
        if (isset($this->bots)) {
            return $this->bots;
        }

        // Parse the bot edits.
        $this->bots = [];

        $limit = $this->tooManyRevisions() ? $this->repository->getMaxPageRevisions() : null;

        $botData = $this->repository->getBotData($this->page, $this->start, $this->end, $limit);
        while ($bot = $botData->fetchAssociative()) {
            $this->bots[$bot['username']] = [
                'count' => (int)$bot['count'],
                'current' => '1' === $bot['current'],
            ];
        }

        // Sort by edit count.
        uasort($this->bots, function ($a, $b) {
            return $b['count'] - $a['count'];
        });

        return $this->bots;
    }

    /**
     * Get the number of bots that edited the page.
     * @return int
     */
    public function getNumBots(): int
    {
        return count($this->getBots());
    }
}
