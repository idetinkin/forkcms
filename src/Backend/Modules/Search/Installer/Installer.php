<?php

namespace Backend\Modules\Search\Installer;

use Backend\Core\Engine\Model as BackendModel;
use Backend\Core\Installer\ModuleInstaller;
use Backend\Modules\Pages\Domain\ModuleExtra\ModuleExtraType;
use Backend\Modules\Pages\Domain\Page\Page;
use Backend\Modules\Pages\Domain\Page\PageRepository;
use Backend\Modules\Pages\Domain\PageBlock\PageBlock;
use Backend\Modules\Pages\Domain\PageBlock\PageBlockRepository;

/**
 * Installer for the search module
 */
class Installer extends ModuleInstaller
{
    /** @var int */
    private $searchBlockId;

    public function install(): void
    {
        $this->addModule('Search');
        $this->importSQL(__DIR__ . '/Data/install.sql');
        $this->importLocale(__DIR__ . '/Data/locale.xml');
        $this->configureSettings();
        $this->configureBackendNavigation();
        $this->configureBackendRights();
        $this->configureFrontendExtras();
        $this->configureFrontendPages();
        $this->configureFrontendSearchIndexes();
    }

    private function configureBackendNavigation(): void
    {
        // Set navigation for "Modules"
        $navigationModulesId = $this->setNavigation(null, 'Modules');
        $navigationSearchId = $this->setNavigation($navigationModulesId, 'Search');
        $this->setNavigation($navigationSearchId, 'Statistics', 'search/statistics');
        $this->setNavigation(
            $navigationSearchId,
            'Synonyms',
            'search/synonyms',
            ['search/add_synonym', 'search/edit_synonym']
        );

        // Set navigation for "Settings"
        $navigationSettingsId = $this->setNavigation(null, 'Settings');
        $navigationModulesId = $this->setNavigation($navigationSettingsId, 'Modules');
        $this->setNavigation($navigationModulesId, 'Search', 'search/settings');
    }

    private function configureBackendRights(): void
    {
        $this->setModuleRights(1, $this->getModule());
        $this->setActionRights(1, $this->getModule(), 'AddSynonym');
        $this->setActionRights(1, $this->getModule(), 'DeleteSynonym');
        $this->setActionRights(1, $this->getModule(), 'EditSynonym');
        $this->setActionRights(1, $this->getModule(), 'Settings');
        $this->setActionRights(1, $this->getModule(), 'Statistics');
        $this->setActionRights(1, $this->getModule(), 'Synonyms');
    }

    private function configureFrontendExtras(): void
    {
        $this->searchBlockId = $this->insertExtra($this->getModule(), ModuleExtraType::block(), 'Search');
        $this->insertExtra($this->getModule(), ModuleExtraType::widget(), 'SearchForm', 'Form');
    }

    private function configureFrontendPages(): void
    {
        foreach ($this->getLanguages() as $language) {
            if ($this->hasExistingSearchIndex($language)) {
                continue;
            }

            $searchIndexPageTitle = $this->getLocale('Search', 'Core', $language, 'lbl', 'Frontend');
            $this->insertPage(
                [
                    'title' => \SpoonFilter::ucfirst($searchIndexPageTitle),
                    'type' => 'root',
                    'language' => $language,
                ],
                null,
                ['extra_id' => $this->searchBlockId, 'position' => 'main']
            );
        }
    }

    private function configureFrontendSearchIndexes(): void
    {
        $this->makeSearchable('Pages');

        $pageRepository = BackendModel::getContainer()->get(PageRepository::class);

        foreach ($pageRepository->findActivePages() as $page) {
            $this->insertSearchIndexForPage($page->getId(), $page->getLanguage(), $page->getTitle());
            $this->insertSearchIndexForPage(
                $page->getId(),
                $page->getLanguage(),
                $this->getContentFromBlocksForPageRevision($page->getRevisionId())
            );
        }
    }

    private function configureSettings(): void
    {
        $this->setSetting($this->getModule(), 'overview_num_items', 10);
        $this->setSetting($this->getModule(), 'validate_search', true);
    }

    private function getContentFromBlocksForPageRevision(int $pageRevisionId): string
    {
        /** @var PageBlockRepository $pageBlockRepository */
        $pageBlockRepository = BackendModel::getContainer()->get(PageBlockRepository::class);

        $pageBlocks = $pageBlockRepository->findBy(['revisionId' => $pageRevisionId]);

        if (count($pageBlocks) === 0) {
            return '';
        }

        return strip_tags(
            implode(
                ' ',
                array_map(
                    function (PageBlock $pageBlock) {
                        return $pageBlock->getHtml();
                    },
                    $pageBlocks
                )
            )
        );
    }

    private function hasExistingSearchIndex(string $language): bool
    {
        // @todo: Replace with a PageBlockRepository method when it exists.
        return (bool) $this->getDatabase()->getVar(
            'SELECT 1
             FROM pages AS p
             INNER JOIN pages_blocks AS b ON b.revision_id = p.revision_id
             WHERE b.extra_id = ? AND p.language = ?
             LIMIT 1',
            [$this->searchBlockId, $language]
        );
    }

    private function insertSearchIndexForPage(int $id, string $language, string $term): void
    {
        // @todo: Replace with a SearchRepository method when it exists.
        $this->getDatabase()->execute(
            'INSERT INTO search_index (module, other_id, language, field, value, active)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value = ?, active = ?',
            ['Pages', $id, $language, 'title', $term, true, $term, true]
        );
    }
}
