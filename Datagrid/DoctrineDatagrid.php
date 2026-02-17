<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\ORM\EntityManagerInterface;
use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Export\Export;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Webmozart\Assert\Assert;
use Symfony\Component\Form\FormView;

/**
 * Datagrid management class that support and handle pagination, sort, filter
 * and now, export actions.
 *
 * @author Maxime CORSON <maxime.corson@spyrit.net>
 */
class DoctrineDatagrid
{
    public const ACTION = 'action';
    public const ACTION_DATAGRID = 'datagrid';
    public const ACTION_PAGE = 'page';
    public const ACTION_SORT = 'sort';
    public const ACTION_REMOVE_SORT = 'remove-sort';
    public const ACTION_RESET = 'reset';
    public const ACTION_LIMIT = 'limit';
    public const ACTION_ADD_COLUMN = 'add-column';
    public const ACTION_REMOVE_COLUMN = 'remove-column';
    public const PARAM1 = 'param1';
    public const PARAM2 = 'param2';

    protected $request_stack;
    protected $session;
    protected $form_factory;
    protected $router;

    /**
     * The query builder that filter the results.
     */
    protected QueryBuilder $qb;

    protected ?FilterObject $filter;

    protected array $filters = [];

    /**
     * @var array<string, string>
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
    protected int $options;

    /**
     * @var string
     */
    protected string $select;

    protected string $id;

    protected ?string $groupBy = null;

    protected $exports;

    protected $params;

    protected bool $shouldFetchJoinCollection = true;

    /**
     * The manager name used for queries.
     * Null is the perfect value if only one manager is used.
     */
    protected ?string $managerName = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        RequestStack $requestStack,
        FormFactoryInterface $formFactory,
        RouterInterface $router,
        string $name,
        array $params = []
    ) {
        $this->request_stack = $requestStack;
        $this->form_factory = $formFactory;
        $this->router = $router;
        $this->name = $name;
        $this->params = $params;
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
                case self::ACTION_SORT: $this->updateSort(); break;
                case self::ACTION_PAGE: $this->updatePage(); break;
                case self::ACTION_LIMIT:  $this->limit(); break;
                case self::ACTION_REMOVE_SORT: $this->removeSort(); break;
                case self::ACTION_RESET: $this->reset(); break;
                case self::ACTION_ADD_COLUMN: $this->addColumn(); break;
                case self::ACTION_REMOVE_COLUMN: $this->removeColumn(); break;
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
            ->get($this->getSessionName().'.'.$name, $default);
    }

    private function setSessionValue(string $name, mixed $value): void
    {
        $this->getRequest()
            ->getSession()
            ->set($this->getSessionName().'.'.$name, $value);
    }

    private function removeSessionValue(string $name)
    {
        return $this->getRequest()
            ->getSession()
            ->remove($this->getSessionName().'.'.$name);
    }

    protected function getQueryResults(): Paginator|array
    {
        $countQb = clone $this->qb;
        $this->nbResults = $countQb->select('COUNT(DISTINCT '.$this->id.')')
            ->orderBy($this->id)
            ->getQuery()
            ->getSingleScalarResult();

        $this->nbPages = ceil($this->nbResults / $this->getMaxPerPage());

        $this->qb->select('DISTINCT '.$this->select)
            ->setFirstResult(($this->getCurrentPage() - 1) * $this->getMaxPerPage())
            ->setMaxResults($this->getMaxPerPage());

        if ($this->groupBy) {
            $this->qb->groupBy($this->groupBy);
        }

        return $this->createPaginator($this->qb->getQuery());
    }

    protected function createPaginator($query): Paginator
    {
        return new Paginator($query, $this->shouldFetchJoinCollection);
    }

    protected function createCountQb(QueryBuilder $qb): QueryBuilder
    {
        return clone $qb;
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

    public function filter($name, $type, $options, $callback): static
    {
        $this->filters[$name] = [
            'type' => $type,
            'options' => $options,
            'query' => $callback,
        ];

        return $this;
    }

    public function sort(string $column, string $order = 'asc'): static
    {
        $this->sorts[$column] = $order;

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
                array_map('strtoupper', $this->getAllowedFilterMethods())
            ) && $this->getRequest()->get($this->filter->getForm()->getName())
        ) {
            $this->setCurrentPage(1);
            $data = $this->getRequest()->get($this->filter->getForm()->getName());
        } else {
            $data = $this->getSessionValue('filter', $this->getDefaultFilters());
        }

        if ($this->filter) {
            $this->filter->submit($data);
            $form = $this->filter->getForm();
            $formData = $form->getData();

            if ($form->isValid()) {
                if (in_array(
                    $this->getRequest()->getMethod(),
                    array_map('strtoupper', $this->getAllowedFilterMethods())
                )) {
                    $this->setSessionValue('filter', $data);
                }
                $this->applyFilter($formData);
            }
        }

        return $this;
    }

    private function applyFilter($data): void
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
            $this->filter = new FilterObject($this->getFormFactory(), $this->name);

            foreach ($this->filters as $name => $filter) {
                $this->filter->add(
                    $name,
                    $filter['type'],
                    isset($filter['options']) ? $filter['options'] : [],
                    isset($filter['value']) ? $filter['value'] : null
                );
            }
            $this->configureFilterBuilder($this->filter->getBuilder());
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

    public function setDefaultFilters($defaultFilters): static
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
    public function getFilterFormView(): FormView
    {
        return $this->filter->getForm()->createView();
    }

    /*public function configureFilterForm()
    {
        return array();
    }*/

    public function configureFilterBuilder($builder): void
    {
        /*
         * Do what you want with the builder.
         * For example, add Event Listener PRE/POST_SET_DATA, etc.
         */
        return;
    }

    public function getAllowedFilterMethods(): array
    {
        if (isset($this->params['method']) && ('get' === $this->params['method'])) {
            return ['get'];
        }
        return ['post'];
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
        if (isset($this->params['multi_sort']) && (false == $this->params['multi_sort'])) {
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

    public function export($name, $params = []): mixed
    {
        $class = $this->getExport($name);
        $this->buildForm();
        $this->doSort();
        $this->doFilter();

        $qb = $this->getQueryBuilder()->select($this->select);

        $export = new $class($qb, $params);

        return $export->execute();
    }

    protected function getExport(string $name): ?string
    {
        $exports = $this->getExports();

        if (!isset($exports[$name])) {
            throw new \Exception('The "'.$name.'" export doesn\'t exist in this datagrid.');
        }

        return $exports[$name];
    }

    /**
     * @param Export[] $exports
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
        return 'datagrid.'.$this->name;
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

    public function setCurrentPage(int $page)
    {
        return $this->setSessionValue('page', $page);
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
        $this->buildForm();
        $this->doSort();
        $this->doFilter();

        $qb = $this->getQueryBuilder()->select($this->select);

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

    protected function getRequestedAction($default = null): string
    {
        return $this->getRequest()->get(self::ACTION, $default);
    }

    protected function getRequestedDatagrid(mixed $default = null): mixed
    {
        return $this->getRequest()->get(self::ACTION_DATAGRID, $default);
    }

    protected function getRequestedSortColumn(?string $default = null): ?string
    {
        $requested = $this->getRequest()->get(self::PARAM1, $default);

        // if there is a whitelist, ignore everything that is not in it
        if (null !== $this->allowedSorts && !in_array($requested, $this->allowedSorts)) {
            $requested = null;
        }

        return $requested ?? $default;
    }

    protected function getRequestedSortOrder(?string $default = null): ?string
    {
        $requested = strtolower($this->getRequest()->get(self::PARAM2, $default));

        // if there is a whitelist, ignore everything that is not in it
        if (!in_array($requested, ['asc', 'desc'])) {
            $requested = null;
        }

        return $requested ?? $default;
    }

    protected function getRequestedSortedColumnRemoval(?string $default = null): ?string
    {
        return $this->getRequest()->get(self::PARAM1, $default);
    }

    protected function getRequestedPage(int $default = null): int
    {
        $page = (int) $this->getRequest()->get(self::PARAM1, $default);

        return $page > 0 ? $page : $default;
    }

    protected function getRequestedNewColumn($default = null)
    {
        return $this->getRequest()->get(self::PARAM1, $default);
    }

    protected function getRequestedPrecedingNewColumn($default = null)
    {
        return $this->getRequest()->get(self::PARAM2, $default);
    }

    protected function getRequestedColumnRemoval($default = null)
    {
        return $this->getRequest()->get(self::PARAM1, $default);
    }

    protected function getRequestedLimit(int $default = null): int
    {
        return $this->getRequest()->get(self::PARAM1, $default);
    }

    /*********************************/
    /** Global service shortcuts *****/
    /*********************************/

    /**
     * Shortcut to return the request service.
     */
    protected function getRequest(): Request
    {
        return $this->request_stack->getCurrentRequest();
    }

    /**
     * Shortcut to return the request service.
     */
    protected function getSession(): SessionInterface
    {
        return $this->session;
    }

    /**
     * return the Form Factory Service.
     */
    protected function getFormFactory(): FormFactoryInterface
    {
        return $this->form_factory;
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
        return (array) json_decode($this->getRequest()->cookies->get($this->name.'_batch'));
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
}
