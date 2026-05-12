<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\RouterInterface;
use Webmozart\Assert\Assert;

/**
 * Datagrid management class that support and handle pagination, sort, filter
 * and now, export actions.
 *
 * @author Maxime CORSON <maxime.corson@spyrit.net>
 */
class DoctrineDatagrid
{
    public const VERSION_SYMFONY7 = 'symfony7';

    public const ACTION = 'action';
    public const ACTION_DATAGRID = 'datagrid';
    public const ACTION_PAGE = 'page';
    public const ACTION_SORT = 'sort';
    public const ACTION_REMOVE_SORT = 'remove-sort';
    public const ACTION_RESET = 'reset';
    public const ACTION_LIMIT = 'limit';
    public const ACTION_ADD_COLUMN = 'add-column';
    public const ACTION_REMOVE_COLUMN = 'remove-column';

    public const ALLOWED_ACTIONS = [
        self::ACTION,
        self::ACTION_DATAGRID,
        self::ACTION_PAGE,
        self::ACTION_SORT,
        self::ACTION_REMOVE_SORT,
        self::ACTION_RESET,
        self::ACTION_LIMIT,
        self::ACTION_ADD_COLUMN,
        self::ACTION_REMOVE_COLUMN,
    ];

    public const ALLOWED_SORT_DIRECTIONS = ['ASC', 'DESC'];


    public const PARAM1 = 'param1';
    public const PARAM2 = 'param2';

    protected SessionInterface $session;

    /**
     * The query builder that filter the results.
     */
    protected QueryBuilder $qb;

    protected ?FilterObject $filterObject = null;

    /**
     * @var array<
     *     string,
     *     array{
     *         type: class-string<FormTypeInterface>,
     *         options: mixed[],
     *         query: callable
     *     }
     * >
     */
    protected array $filters = [];

    /**
     * @var array<string, 'ASC'|'DESC'>
     */
    protected array $sorts = [];

    protected ?array $allowedSorts = null;

    protected array $defaultFilters = [];

    protected array $defaultSorts = [];

    /**
     * Results of the query (in fact this is a Paginator object which contains
     * the result set and some methods to display pager and extra things).
     */
    protected Paginator $results;

    /**
     * Number of result(s) to display per page.
     */
    protected int $maxPerPage;

    /**
     * Default number of result(s) to display per page.
     */
    protected int $defaultMaxPerPage = 30;

    protected int $nbResults;

    protected int $nbPages;

    /**
     * Options that you can use in your Datagrid methods if you need.
     */
    protected mixed $options;

    /**
     * @var string
     */
    protected string $select;

    protected string $id;

    protected bool $distinct = true;

    protected ?string $groupBy = null;

    /** @var array<string, class-string> */
    protected array $exports = [];

    protected bool $shouldFetchJoinCollection = true;

    /**
     * The manager name used for queries.
     * Null is the perfect value if only one manager is used.
     */
    protected ?string $managerName = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
        private readonly FormFactoryInterface $formFactory,
        private readonly RouterInterface $router,
        private string $name,
        private array $params = []
    )
    {
        $this->session = $requestStack->getSession();
    }

    public function create($name, $params = []): void
    {
        $this->name = $name;
        $this->params = $params;
    }

    public function execute(): static
    {
        $this->check();
        $this->buildForm();
        $this->controller();

        return $this;
    }

    protected function check(): true
    {
        return true;
    }

    public function reset(): static
    {
        return $this
            ->resetFilters()
            ->resetSort()
            ->resetPage();
    }

    private function controller(): void
    {
        if ($this->isRequestedDatagrid()) {
            switch ($this->getRequestedAction()) {
                case self::ACTION_SORT:
                    $this->updateSort();
                    break;
                case self::ACTION_PAGE:
                    $this->updatePage();
                    break;
                case self::ACTION_LIMIT:
                    $this->limit();
                    break;
                case self::ACTION_REMOVE_SORT:
                    $this->removeSort();
                    break;
                case self::ACTION_RESET:
                    $this->reset();
                    break;
                case self::ACTION_ADD_COLUMN:
                    $this->addColumn();
                    break;
                case self::ACTION_REMOVE_COLUMN:
                    $this->removeColumn();
                    break;
            }
        }
        $this->doSort();
        $this->doFilter();
        $this->results = $this->getQueryResults();
    }

    public function setManagerName($name): static
    {
        $this->managerName = $name;

        return $this;
    }

    private function getManager(): EntityManagerInterface
    {
        return $this->em;
    }

    private function isRequestedDatagrid(): bool
    {
        return $this->getRequestedDatagrid() === $this->name;
    }

    private function isRequestedAction(?string $action): bool
    {
        return $this->getRequest()->getString(self::ACTION) === $action;
    }

    private function getSessionValue(string $name, mixed $default = null): mixed
    {
        return $this->getRequest()
            ->getSession()
            ->get($this->getSessionName() . '.' . $name, $default);
    }

    private function setSessionValue(string $name, mixed $value): void
    {
        $this->getRequest()
            ->getSession()
            ->set($this->getSessionName() . '.' . $name, $value);
    }

    private function removeSessionValue(string $name)
    {
        return $this->getRequest()
            ->getSession()
            ->remove($this->getSessionName() . '.' . $name);
    }

    protected function getQueryResults(): Paginator|array
    {
        $countQb = $this->createCountQb($this->qb);
        $countSelect = 'COUNT(' . ($this->distinct ? 'DISTINCT ' : '') . $this->id . ')';
        $this->nbResults = $countQb->select($countSelect)
            ->orderBy($this->id)
            ->getQuery()
            ->getSingleScalarResult();

        $this->nbPages = (int) ceil($this->nbResults / $this->getMaxPerPage());

        $qb = $this->qb->select(($this->distinct ? 'DISTINCT ' : '') . $this->select)
            ->setFirstResult(($this->getCurrentPage() - 1) * $this->getMaxPerPage())
            ->setMaxResults($this->getMaxPerPage());

        if ($this->groupBy) {
            $qb = $this->qb->groupBy($this->groupBy);
        }

        return $this->createPaginator($qb);
    }

    protected function createPaginator(Query|QueryBuilder $query): Paginator
    {
        return new Paginator($query, $this->shouldFetchJoinCollection);
    }

    public function getUnorderedQueryBuilder(QueryBuilder $qb): QueryBuilder
    {
        return (clone $qb)->resetDQLPart('orderBy');
    }

    protected function createCountQb(QueryBuilder $qb): QueryBuilder
    {
        return $this->getUnorderedQueryBuilder($qb);
    }

    /*********************************/
    /** Query features ***************/
    /*********************************/

    public function select(string|array $select): static
    {
        if (is_array($select)) {
            $select = implode(', ', $select);
        }

        Assert::string($select);
        $this->select = $select;

        return $this;
    }

    public function distinct(bool $distinct = true): static
    {
        $this->distinct = $distinct;

        return $this;
    }

    /**
     * @param string|string[] $groupBy
     */
    public function groupBy(string|array $groupBy): static
    {
        if (is_array($groupBy)) {
            $groupBy = implode(', ', $groupBy);
        }

        Assert::string($groupBy);
        $this->groupBy = $groupBy;

        return $this;
    }

    public function query(callable $callback): static
    {
        $this->qb = $this->getManager()->createQueryBuilder();

        $this->qb = call_user_func($callback, $this->qb);

        return $this;
    }

    /**
     * @param string $name
     * @param class-string<FormTypeInterface> $type
     * @param mixed[] $options
     * @param callable $callback
     */
    public function filter(string $name, string $type, array $options, callable $callback): static
    {
        $this->filters[$name] = [
            'type' => $type,
            'options' => $options,
            'query' => $callback,
        ];

        return $this;
    }

    /**
     * @param 'ASC'|'DESC'|'asc'|'desc' $order
     */
    public function sort(string $column, string $order = 'ASC'): static
    {
        $sortDirection = Util::validateSortDirection($order);
        $this->sorts[$column] = $sortDirection;

        return $this;
    }

    public function id(string $id): static
    {
        $this->id = $id;

        return $this;
    }

    /*********************************/
    /** Filter features here *********/
    /*********************************/

    private function doFilter(): static
    {
        if (in_array(
                $this->getRequest()->getMethod(),
                $this->getAllowedFilterMethods()
            ) && $this->filterObject && $this->getRequestParams($this->getRequest())->all($this->filterObject->getForm()->getName())
        ) {
            $this->setCurrentPage(1);
            $data = $this->getRequestParams($this->getRequest())->all($this->filterObject->getForm()->getName());
        } else {
            $data = $this->getSessionValue('filter', $this->getDefaultFilters());
        }

        if ($this->filterObject) {
            // Prevent submitting twice
            if (!$this->filterObject->getForm()->isSubmitted()) {
                $this->filterObject->submit($data);
            }
            $form = $this->filterObject->getForm();
            $formData = $form->getData();

            if ($form->isValid()) {
                if (in_array(
                    $this->getRequest()->getMethod(),
                    $this->getAllowedFilterMethods()
                )) {
                    $this->setSessionValue('filter', $data);
                }
                $this->applyFilter($formData);
            }
        }

        return $this;
    }

    private function applyFilter(mixed $data): void
    {
        $qb = $this->qb;
        foreach ($data as $key => $value) {
            if (!isset($this->filters[$key]['query'])) {
                throw new \Exception("There is no filter method defined for the field '{$key}'.");
            }
            if (isset($value)) {
                $qb = call_user_func_array($this->filters[$key]['query'], [$value, $qb]);
            }
        }
        $this->setQueryBuilder($qb);
    }

    private function buildForm(): void
    {
        if (!empty($this->filters)) {
            $this->filterObject = new FilterObject($this->getFormFactory(), $this->name);

            foreach ($this->filters as $name => $filter) {
                $this->filterObject->add(
                    $name,
                    $filter['type'],
                    $filter['options'] ?? [],
                    $filter['value'] ?? null
                );
            }
            $this->configureFilterBuilder($this->filterObject->getBuilder());
        }
    }

    public function setFilterValue(string $name, mixed $value): void
    {
        $filters = $this->getSessionValue('filter', []);
        $filters[$name] = $value;
        $this->setSessionValue('filter', $filters);
    }

    public function getDefaultFilters(): array
    {
        return $this->defaultFilters;
    }

    public function setAllowedSorts($allowedSorts): static
    {
        $this->allowedSorts = $allowedSorts;

        return $this;
    }

    public function setDefaultFilters(array $defaultFilters): static
    {
        $this->defaultFilters = $defaultFilters;

        return $this;
    }

    public function resetFilters(): static
    {
        $this->removeSessionValue('filter');

        return $this;
    }

    private function getSessionFilter(mixed $default = []): mixed
    {
        return $this->getSessionValue('filter', $default);
    }

    private function setSessionFilter(mixed $value): static
    {
        $this->setSessionValue('filter', $value);

        return $this;
    }

    /**
     * Shortcut.
     */
    public function getFilterFormView(): ?FormView
    {
        return $this->filterObject?->getForm()->createView();
    }

    /**
     * Do what you want with the builder.
     * For example, add an Event Listener PRE/POST_SET_DATA, etc.
     */
    public function configureFilterBuilder(FormBuilderInterface $builder): void
    {
        return;
    }

    /**
     * @return array<'GET'|'POST'>
     */
    public function getAllowedFilterMethods(): array
    {
        if (isset($this->params['method']) && ('get' === $this->params['method'])) {
            return [Request::METHOD_GET];
        }
        return [Request::METHOD_POST];
    }

    /*********************************/
    /** Sort features here ***********/
    /*********************************/

    public function setDefaultSort($defaultSort): static
    {
        $this->defaultSorts = $defaultSort;

        return $this;
    }

    protected function doSort(): void
    {
        $sort = $this->getSessionValue('sort', $this->defaultSorts);

        foreach ($sort as $column => $order) {
            $this->getQueryBuilder()->addOrderBy($column, $order);
        }
    }

    public function updateSort(): void
    {
        $sorts = $this->getSessionValue('sort', $this->defaultSorts);
        if (isset($this->params['multi_sort']) && !$this->params['multi_sort']) {
            $sorts = [];
        }
        if ($sortColumn = $this->getRequestedSortColumn()) {
            $sorts[$sortColumn] = $this->getRequestedSortOrder();
        }
        $this->setSessionValue('sort', $sorts);
    }

    public function removeSort(): void
    {
        $sort = $this->getSessionValue('sort', $this->defaultSorts);
        unset($sort[$this->getRequestedSortedColumnRemoval()]);
        $this->setSessionValue('sort', $sort);
    }

    public function isSortedColumn(string $column): bool
    {
        $sort = $this->getSessionValue('sort', $this->defaultSorts);

        return isset($sort[$column]);
    }

    public function getSortedColumnOrder(string $column): string
    {
        $sort = $this->getSessionValue('sort', $this->defaultSorts);

        return $sort[$column];
    }

    public function getSortedColumnPriority(string $column): false|int|string
    {
        $sort = $this->getSessionValue('sort', $this->defaultSorts);

        return array_search($column, array_keys($sort));
    }

    public function getSortCount(): int
    {
        $sort = $this->getSessionValue('sort', $this->defaultSorts);

        return count($sort);
    }

    public function resetSort(): static
    {
        $this->removeSessionValue('sort');

        return $this;
    }

    /*********************************/
    /** Export features here *********/
    /*********************************/

    public function export(string $name, array $params = []): mixed
    {
        $class = $this->getExport($name);
        $this->buildForm();
        $this->doSort();
        $this->doFilter();

        $qb = $this->getQueryBuilder()->select(($this->distinct ? 'DISTINCT ' : '') . $this->select);

        $export = new $class($qb, $params);

        return $export->execute();
    }

    protected function getExport(string $name): ?string
    {
        $exports = $this->getExports();

        if (!isset($exports[$name])) {
            throw new \Exception('The "' . $name . '" export doesn\'t exist in this datagrid.');
        }

        return $exports[$name];
    }

    /**
     * @param array<string, class-string> $exports
     */
    public function setExports(array $exports): static
    {
        $this->exports = $exports;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    protected function getExports(): array
    {
        return $this->exports;
    }

    public function getSessionName(): string
    {
        return 'datagrid.' . $this->name;
    }

    protected function updatePage(): static
    {
        $this->setSessionValue('page', $this->getRequestedPage(1));

        return $this;
    }

    protected function resetPage(): static
    {
        $this->removeSessionValue('page');

        return $this;
    }

    public function getCurrentPage(): int
    {
        return $this->getSessionValue('page', 1);
    }

    public function setCurrentPage(int $page): static
    {
        $this->setSessionValue('page', $page);

        return $this;
    }

    public function getNbResults(): int
    {
        return $this->nbResults;
    }

    public function getNbPages(): int
    {
        return $this->nbPages;
    }

    public function getAllResults(): mixed
    {
        $this->doSort();
        $this->doFilter();

        $qb = $this->getQueryBuilder()->select(($this->distinct ? 'DISTINCT ' : '') . $this->select);

        return $qb->getQuery()->execute();
    }

    /*********************************/
    /** Dynamic columns feature here */
    /*********************************/

    private function removeColumn(): void
    {
        $columnToRemove = $this->getRequestedColumnRemoval();
        $columns = $this->getColumns();

        if (array_key_exists($columnToRemove, $columns)) {
            unset($columns[$columnToRemove]);
            $this->setSessionValue('columns', $columns);
            /*
             * @todo Remove sort on the removed column
             */
        }
    }

    private function addColumn(): void
    {
        $newColumn = $this->getRequestedNewColumn();
        $precedingColumn = $this->getRequestedPrecedingNewColumn();

        if (array_key_exists($newColumn, $this->getAvailableAppendableColumns())) {
            $columns = $this->getColumns();
            $newColumnsArray = [];

            foreach ($columns as $column => $label) {
                $newColumnsArray[$column] = $label;
                if ($column == $precedingColumn) {
                    $cols = array_merge(
                        $this->getAppendableColumns(),
                        $this->getDefaultColumns()
                    );
                    $newColumnsArray[$newColumn] = $cols[$newColumn];
                }
            }
            $this->setSessionValue('columns', $newColumnsArray);
        }
    }

    public function getDefaultColumns(): array
    {
        return [];
    }

    public function getNonRemovableColumns(): array
    {
        return [];
    }

    public function getAppendableColumns(): array
    {
        return [];
    }

    public function getAvailableAppendableColumns(): array
    {
        $columns = $this->getSessionValue('columns', $this->getDefaultColumns());

        return array_merge(
            array_diff_key($this->getAppendableColumns(), $columns),
            array_diff_key($this->getDefaultColumns(), $columns)
        );
    }

    public function getColumns()
    {
        return $this->getSessionValue('columns', $this->getDefaultColumns());
    }

    /*********************************/
    /** Max per page feature here ****/
    /*********************************/

    private function limit(): void
    {
        $limit = $this->getRequestedLimit();

        if (in_array($limit, $this->getAvailableMaxPerPage())) {
            $this->setSessionValue('limit', $limit);
        }
    }

    /**
     * @return int[]
     */
    public function getAvailableMaxPerPage(): array
    {
        return [15, 30, 50];
    }

    public function getDefaultMaxPerPage(): int
    {
        return $this->defaultMaxPerPage;
    }

    public function setDefaultMaxPerPage(int $maxPerPage): static
    {
        $this->defaultMaxPerPage = $maxPerPage;

        return $this;
    }

    public function getMaxPerPage(): int
    {
        return $this->getSessionValue('limit', $this->getDefaultMaxPerPage());
    }

    public function setMaxPerPage(int $value): static
    {
        $this->setSessionValue('limit', $value);

        return $this;
    }

    /*********************************/
    /** Routing helper methods here **/
    /*********************************/

    /**
     * @param null|'action'|'datagrid'|'page'|'sort'|'remove-sort'|'reset'|'limit'|'add-column'|'remove-column' $default
     * @return string
     */
    protected function getRequestedAction(?string $default = null): string
    {
        Assert::inArray($default, [null, ...self::ALLOWED_ACTIONS], 'Action must be one of: ' . implode(', ', self::ALLOWED_ACTIONS) . '. %s given.');

        return $this->getRequestParams($this->getRequest())->get(self::ACTION, $default);
    }

    protected function getRequestedDatagrid(?string $default = null): mixed
    {
        return $this->getRequestParams($this->getRequest())->get(self::ACTION_DATAGRID, $default);
    }

    protected function getRequestedSortColumn(?string $default = null): ?string
    {
        $requested = $this->getRequestParams($this->getRequest())->get(self::PARAM1, $default);

        // if there is a whitelist, ignore everything that is not in it
        if (null !== $this->allowedSorts && !in_array($requested, $this->allowedSorts)) {
            $requested = null;
        }

        return $requested ?? $default;
    }

    /**
     * @param null|'ASC'|'DESC'|'asc'|'desc' $default
     * @return string|null
     */
    protected function getRequestedSortOrder(?string $default = null): ?string
    {
        $dir = $this->getRequestParams($this->getRequest())->get(self::PARAM2, $default);
        $requested = $dir ? Util::validateSortDirection($dir) : null;

        // if there is a whitelist, ignore everything that is not in it
        if (!in_array($requested, self::ALLOWED_SORT_DIRECTIONS, true)) {
            $requested = null;
        }

        return $requested ?? $default;
    }

    protected function getRequestedSortedColumnRemoval(?string $default = null): ?string
    {
        return $this->getRequestParams($this->getRequest())->get(self::PARAM1, $default);
    }

    protected function getRequestedPage(?int $default = null): int
    {
        $page = $this->getRequestParams($this->getRequest())->getInt(self::PARAM1, $default);

        return $page > 0 ? $page : $default;
    }

    protected function getRequestedNewColumn(?string $default = null)
    {
        return $this->getRequestParams($this->getRequest())->get(self::PARAM1, $default);
    }

    protected function getRequestedPrecedingNewColumn(?string $default = null)
    {
        return $this->getRequestParams($this->getRequest())->get(self::PARAM2, $default);
    }

    protected function getRequestedColumnRemoval(?string $default = null)
    {
        return $this->getRequestParams($this->getRequest())->get(self::PARAM1, $default);
    }

    protected function getRequestedLimit(?int $default = null): int
    {
        return $this->getRequestParams($this->getRequest())->get(self::PARAM1, $default);
    }

    /*********************************/
    /** Global service shortcuts *****/
    /*********************************/

    protected function getRequest(): Request
    {
        return $this->requestStack->getCurrentRequest();
    }

    protected function getSession(): SessionInterface
    {
        return $this->session;
    }

    /**
     * return the Form Factory Service.
     */
    protected function getFormFactory(): FormFactoryInterface
    {
        return $this->formFactory;
    }

    public function getQueryBuilder(): QueryBuilder
    {
        return $this->qb;
    }

    public function setQueryBuilder(QueryBuilder $qb): static
    {
        $this->qb = $qb;

        return $this;
    }

    public function getResults(): mixed
    {
        return $this->results;
    }

    public function getDefaultSortOrder(): string
    {
        return 'ASC';
    }

    /**
     * Generate pagination route.
     */
    public function getPaginationPath(string $route, int $page, array $extraParams = []): string
    {
        $params = [
            self::ACTION => self::ACTION_PAGE,
            self::ACTION_DATAGRID => $this->name,
            self::PARAM1 => $page,
        ];

        return $this->router->generate($route, array_merge($params, $extraParams));
    }

    /**
     * Generate reset route for the button view.
     */
    public function getResetPath(string $route, array $extraParams = []): string
    {
        $params = [
            self::ACTION => self::ACTION_RESET,
            self::ACTION_DATAGRID => $this->name,
        ];

        return $this->router->generate($route, array_merge($params, $extraParams));
    }

    /**
     * Generate sorting route for a given column to be displayed in view.
     *
     * @todo Remove the order parameter and ask to the datagrid to guess it ?
     */
    public function getSortPath(string $route, string $column, string $order, array $extraParams = []): string
    {
        $params = [
            self::ACTION => self::ACTION_SORT,
            self::ACTION_DATAGRID => $this->name,
            self::PARAM1 => $column,
            self::PARAM2 => $order,
        ];

        return $this->router->generate($route, array_merge($params, $extraParams));
    }

    /**
     * Generate remove sort route for a given column to be displayed in view.
     */
    public function getRemoveSortPath(string $route, string $column, array $extraParams = []): string
    {
        $params = [
            self::ACTION => self::ACTION_REMOVE_SORT,
            self::ACTION_DATAGRID => $this->name,
            self::PARAM1 => $column,
        ];

        return $this->router->generate($route, array_merge($params, $extraParams));
    }

    /**
     * Generate new column route for a given column to be displayed in view.
     */
    public function getNewColumnPath(string $route, string $newColumn, string $precedingColumn, array $extraParams = []): string
    {
        $params = [
            self::ACTION => self::ACTION_ADD_COLUMN,
            self::ACTION_DATAGRID => $this->name,
            self::PARAM1 => $newColumn,
            self::PARAM2 => $precedingColumn,
        ];

        return $this->router->generate($route, array_merge($params, $extraParams));
    }

    /**
     * Generate remove column route for a given column to be displayed in view.
     */
    public function getRemoveColumnPath(string $route, string $column, array $extraParams = []): string
    {
        $params = [
            self::ACTION => self::ACTION_REMOVE_COLUMN,
            self::ACTION_DATAGRID => $this->name,
            self::PARAM1 => $column,
        ];

        return $this->router->generate($route, array_merge($params, $extraParams));
    }

    /**
     * Generate max per page route to be displayed in view.
     */
    public function getMaxPerPagePath(string $route, int $limit, array $extraParams = []): string
    {
        $params = [
            self::ACTION => self::ACTION_LIMIT,
            self::ACTION_DATAGRID => $this->name,
            self::PARAM1 => $limit,
        ];

        return $this->router->generate($route, array_merge($params, $extraParams));
    }

    public function getBatchData(): array
    {
        return (array)json_decode($this->getRequest()->cookies->get($this->name . '_batch'));
    }

    public function isBatchChecked(mixed $identifier): bool
    {
        $data = $this->getBatchData();
        if ($data) {
            if ('include' == $data['type'] && in_array($identifier, $data['checked'])) {
                return true;
            } elseif ('exclude' == $data['type'] && !in_array($identifier, $data['checked'])) {
                return true;
            }
        }

        return false;
    }

    public function hasAllCheckedBatch(): bool
    {
        $data = $this->getBatchData();
        if ($data) {
            if ('include' == $data['type'] && count($data['checked']) == count($this->getResults())) {
                return true;
            } elseif ('exclude' == $data['type'] && 0 == count($data['checked'])) {
                return true;
            }
        }

        return false;
    }

    public function hasCheckedBatch(): bool
    {
        $data = $this->getBatchData();
        if ($data) {
            if ('include' == $data['type'] && count($data['checked']) > 0) {
                return true;
            } elseif ('exclude' == $data['type'] && count($data['checked']) < count($this->getResults())) {
                return true;
            }
        }

        return false;
    }

    public function isFiltered(): bool
    {
        $filters = $this->getSessionValue('filter');

        return null !== $filters && count($filters) > 0;
    }

    public function shouldFetchJoinCollection(bool $shouldFetchJoinCollection): static
    {
        $this->shouldFetchJoinCollection = $shouldFetchJoinCollection;

        return $this;
    }

    public function getRequestParams(Request $request): ParameterBag
    {
        if ($request->getMethod() === Request::METHOD_POST) {
            return $request->request;
        }

        return $request->query;
    }
}
