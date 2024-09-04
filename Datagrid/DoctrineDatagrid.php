<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Exception;
use Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Export\Export;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

/**
 * Datagrid management class that support and handle pagination, sort, filter
 * and now, export actions.
 *
 * @author Maxime CORSON <maxime.corson@spyrit.net>
 */
class DoctrineDatagrid
{
    const ACTION = 'action';
    const ACTION_DATAGRID = 'datagrid';
    const ACTION_PAGE = 'page';
    const ACTION_SORT = 'sort';
    const ACTION_REMOVE_SORT = 'remove-sort';
    const ACTION_RESET = 'reset';
    const ACTION_LIMIT = 'limit';
    const ACTION_ADD_COLUMN = 'add-column';
    const ACTION_REMOVE_COLUMN = 'remove-column';

    const PARAM1 = 'param1';
    const PARAM2 = 'param2';

    /**
     * The query builder that filter the results.
     */
    protected QueryBuilder $qb;

    protected FilterObject $filter;

    protected array $filters = [];

    protected array $sorts = [];

    protected ?array $allowedSorts = null;

    /**
     * @var array
     */
    protected array $defaultFilters = [];

    /**
     * @var array
     */
    protected array $defaultSorts = [];

    /**
     * Results of the query (in fact this is a Paginator object which contains
     * the result set and some methods to display pager and extra things).
     */
    protected mixed $results;

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

    protected string $select;

    protected string $id;

    protected string $groupBy;

    protected mixed $exports;

    /**
     * The manager name used for queries.
     * Null is the perfect value if only one manager is used.
     */
    protected ?string $managerName = null;

    public function __construct(
        protected EntityManagerInterface $entityManager,
        protected RequestStack $requestStack,
        protected FormFactoryInterface $formFactory,
        protected RouterInterface $router,
        protected string $name,
        protected array $params = []
    ) {
    }

    public function create($name, $params = []): void
    {
        $this->name = $name;
        $this->params = $params;
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function execute(): static
    {
        $this->buildForm();
        $this->controller();

        return $this;
    }

    /**
     * @throws Exception
     */
    public function reset(): static
    {
        return $this
            ->resetFilters()
            ->resetSort()
            ->resetPage();
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     * @throws Exception
     */
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
        return $this->entityManager;
    }

    /**
     * @throws Exception
     */
    private function isRequestedDatagrid(): bool
    {
        return $this->getRequestedDatagrid() == $this->name;
    }

    /**
     * @throws Exception
     */
    private function isRequestedAction($action): bool
    {
        return $this->getRequest()->get(self::ACTION) == $action;
    }

    /**
     * @throws Exception
     */
    private function getSessionValue($name, $default = null)
    {
        return $this->getRequest()
            ->getSession()
            ->get($this->getSessionName().'.'.$name, $default);
    }

    /**
     * @throws Exception
     */
    private function setSessionValue($name, $value) : void
    {
        $this->getRequest()
            ->getSession()
            ->set($this->getSessionName().'.'.$name, $value);
    }

    /**
     * @throws Exception
     */
    private function removeSessionValue($name): void
    {
        $this->getRequest()
            ->getSession()
            ->remove($this->getSessionName() . '.' . $name);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    protected function getQueryResults()
    {
        $countQb = clone $this->qb;
        $this->nbResults = $countQb->select('COUNT(DISTINCT '.$this->id.')')
            ->getQuery()
            ->getSingleScalarResult();

        $this->nbPages = ceil($this->nbResults / $this->getMaxPerPage());

        $qb = $this->qb->select('DISTINCT '.$this->select)
            ->setFirstResult(($this->getCurrentPage() - 1) * $this->getMaxPerPage())
            ->setMaxResults($this->getMaxPerPage());

        if ($this->groupBy) {
            $qb = $this->qb->groupBy($this->groupBy);

            return $qb->getQuery()->getResult();
        }

        return new Paginator($this->qb->getQuery(), true);
    }

    /*********************************/
    /** Query features ***************/
    /*********************************/

    public function select($select): static
    {
        if (is_array($select)) {
            $select = implode(', ', $select);
        }
        $this->select = $select;

        return $this;
    }

    public function groupBy($groupBy): static
    {
        if (is_array($groupBy)) {
            $groupBy = implode(', ', $groupBy);
        }
        $this->groupBy = $groupBy;

        return $this;
    }

    public function query($callback): static
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

    public function sort($column, $order = 'asc'): static
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
    /** Filter features here ********
     * @throws Exception
     */
    /*********************************/

    private function doFilter(): void
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

    /**
     * @throws Exception
     */
    private function applyFilter($data): void
    {
        $qb = $this->qb;
        foreach ($data as $key => $value) {
            if (!isset($this->filters[$key]['query'])) {
                throw new Exception("There is no filter method defined for the field '{$key}'.");
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
                    $filter['options'] ?? [],
                    $filter['value'] ?? null
                );
            }
            $this->configureFilterBuilder($this->filter->getBuilder());
        }
    }

    /**
     * @throws Exception
     */
    public function setFilterValue($name, $value): void
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

    /**
     * @throws Exception
     */
    public function resetFilters(): static
    {
        $this->removeSessionValue('filter');

        return $this;
    }

    /**
     * @throws Exception
     */
    private function getSessionFilter(array $default = [])
    {
        return $this->getSessionValue('filter', $default);
    }

    /**
     * @throws Exception
     */
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
        if (isset($this->params['method']) && ('get' == $this->params['method'])) {
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

    /**
     * @throws Exception
     */
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

    /**
     * @throws Exception
     */
    public function removeSort(): void
    {
        $sort = $this->getSessionValue('sort', $this->defaultSorts);
        unset($sort[$this->getRequestedSortedColumnRemoval()]);
        $this->setSessionValue('sort', $sort);
    }

    /**
     * @throws Exception
     */
    public function isSortedColumn($column): bool
    {
        $sort = $this->getSessionValue('sort', $this->defaultSorts);

        return isset($sort[$column]);
    }

    public function getSortedColumnOrder($column)
    {
        $sort = $this->getSessionValue('sort', $this->defaultSorts);

        return $sort[$column];
    }

    /**
     * @throws Exception
     */
    public function getSortedColumnPriority($column): bool|int|string
    {
        $sort = $this->getSessionValue('sort', $this->defaultSorts);

        return array_search($column, array_keys($sort));
    }

    /**
     * @throws Exception
     */
    public function getSortCount(): int
    {
        $sort = $this->getSessionValue('sort', $this->defaultSorts);

        return count($sort);
    }

    /**
     * @throws Exception
     */
    public function resetSort(): static
    {
        $this->removeSessionValue('sort');

        return $this;
    }

    /*********************************/
    /** Export features here ********
     * @throws Exception
     */
    /*********************************/

    public function export($name, $params = []): Export
    {
        $class = $this->getExport($name);
        $this->buildForm();
        $this->doSort();
        $this->doFilter();

        $qb = $this->getQueryBuilder()->select($this->select);

        $export = new $class($qb, $params);

        return $export->execute();
    }

    /**
     * @throws Exception
     */
    protected function getExport($name)
    {
        $exports = $this->getExports();
        if (!isset($exports[$name])) {
            throw new Exception('The "'.$name.'" export doesn\'t exist in this datagrid.');
        }

        return $exports[$name];
    }

    public function setExports($exports): static
    {
        $this->exports = $exports;

        return $this;
    }

    protected function getExports()
    {
        return $this->exports;
    }

    public function getSessionName(): string
    {
        return 'datagrid.'.$this->name;
    }

    /**
     * @throws Exception
     */
    protected function updatePage(): static
    {
        $this->setSessionValue('page', $this->getRequestedPage(1));

        return $this;
    }

    /**
     * @throws Exception
     */
    protected function resetPage(): static
    {
        $this->removeSessionValue('page');

        return $this;
    }

    /**
     * @throws Exception
     */
    public function getCurrentPage()
    {
        return $this->getSessionValue('page', 1);
    }

    /**
     * @throws Exception
     */
    public function setCurrentPage($page): void
    {
        $this->setSessionValue('page', $page);
    }

    public function getNbResults(): int
    {
        return $this->nbResults;
    }

    public function getNbPages(): int
    {
        return $this->nbPages;
    }

    /**
     * @throws Exception
     */
    public function getAllResults()
    {
        $this->buildForm();
        $this->doSort();
        $this->doFilter();

        $qb = $this->getQueryBuilder()->select($this->select);

        return $qb->getQuery()->execute();
    }

    /*********************************/
    /** Dynamic columns feature here
     * @throws Exception
     */
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

    /**
     * @throws Exception
     */
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

    /**
     * @throws Exception
     */
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
    /** Max per page feature here ***
     * @throws Exception
     */
    /*********************************/

    private function limit(): void
    {
        $limit = $this->getRequestedLimit();

        if (in_array($limit, $this->getAvailableMaxPerPage())) {
            $this->setSessionValue('limit', $limit);
        }
    }

    public function getAvailableMaxPerPage(): array
    {
        return [15, 30, 50];
    }

    public function getDefaultMaxPerPage(): int
    {
        return $this->defaultMaxPerPage;
    }

    public function setDefaultMaxPerPage($maxPerPage): static
    {
        $this->defaultMaxPerPage = $maxPerPage;

        return $this;
    }

    /**
     * @throws Exception
     */
    public function getMaxPerPage()
    {
        return $this->getSessionValue('limit', $this->getDefaultMaxPerPage());
    }

    /**
     * @throws Exception
     */
    public function setMaxPerPage($value): static
    {
        $this->setSessionValue('limit', $value);

        return $this;
    }

    /*********************************/
    /** Routing helper methods here *
     * @throws Exception
     */
    /*********************************/

    protected function getRequestedAction($default = null)
    {
        return $this->getRequest()->get(self::ACTION, $default);
    }

    /**
     * @throws Exception
     */
    protected function getRequestedDatagrid($default = null)
    {
        return $this->getRequest()->get(self::ACTION_DATAGRID, $default);
    }

    /**
     * @throws Exception
     */
    protected function getRequestedSortColumn($default = null)
    {
        $requested = $this->getRequest()->get(self::PARAM1, $default);

        // if there is a whitelist, ignore everything that is not in it
        if (null !== $this->allowedSorts && !in_array($requested, $this->allowedSorts)) {
            $requested = null;
        }

        return $requested ?? $default;
    }

    /**
     * @throws Exception
     */
    protected function getRequestedSortOrder($default = null)
    {
        $requested = strtolower($this->getRequest()->get(self::PARAM2, $default));

        // if there is a whitelist, ignore everything that is not in it
        if (!in_array($requested, ['asc', 'desc'])) {
            $requested = null;
        }

        return $requested ?? $default;
    }

    /**
     * @throws Exception
     */
    protected function getRequestedSortedColumnRemoval($default = null)
    {
        return $this->getRequest()->get(self::PARAM1, $default);
    }

    /**
     * @throws Exception
     */
    protected function getRequestedPage($default = null)
    {
        $page = (int) $this->getRequest()->get(self::PARAM1, $default);

        return $page > 0 ? $page : $default;
    }

    /**
     * @throws Exception
     */
    protected function getRequestedNewColumn($default = null)
    {
        return $this->getRequest()->get(self::PARAM1, $default);
    }

    /**
     * @throws Exception
     */
    protected function getRequestedPrecedingNewColumn($default = null)
    {
        return $this->getRequest()->get(self::PARAM2, $default);
    }

    /**
     * @throws Exception
     */
    protected function getRequestedColumnRemoval($default = null)
    {
        return $this->getRequest()->get(self::PARAM1, $default);
    }

    /**
     * @throws Exception
     */
    protected function getRequestedLimit($default = null)
    {
        return $this->getRequest()->get(self::PARAM1, $default);
    }

    /*********************************/
    /** Global service shortcuts *****/
    /*********************************/

    /**
     * Shortcut to return the request service.
     * @throws Exception
     */
    protected function getRequest(): Request
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$request) {
            throw new Exception('The request service is not available.');
        }

        return $request;
    }

    /**
     * return the Form Factory Service.
     *
     * @return FormFactoryInterface
     */
    protected function getFormFactory(): FormFactoryInterface
    {
        return $this->formFactory;
    }

    public function getQueryBuilder(): QueryBuilder
    {
        return $this->qb;
    }

    public function setQueryBuilder($qb): static
    {
        $this->qb = $qb;

        return $this;
    }

    public function getResults()
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
    public function getPaginationPath($route, $page, $extraParams = []): string
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
    public function getResetPath($route, $extraParams = []): string
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
    public function getSortPath($route, $column, $order, $extraParams = []): string
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
    public function getRemoveSortPath($route, $column, $extraParams = []): string
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
    public function getNewColumnPath($route, $newColumn, $precedingColumn, $extraParams = []): string
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
    public function getRemoveColumnPath($route, $column, $extraParams = []): string
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
    public function getMaxPerPagePath($route, $limit, $extraParams = []): string
    {
        $params = [
            self::ACTION => self::ACTION_LIMIT,
            self::ACTION_DATAGRID => $this->name,
            self::PARAM1 => $limit,
        ];

        return $this->router->generate($route, array_merge($params, $extraParams));
    }

    /**
     * @throws Exception
     */
    public function getBatchData(): array
    {
        return (array) json_decode($this->getRequest()->cookies->get($this->name.'_batch'));
    }

    /**
     * @throws Exception
     */
    public function isBatchChecked($identifier): bool
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

    /**
     * @throws Exception
     */
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

    /**
     * @throws Exception
     */
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

    /**
     * @throws Exception
     */
    public function isFiltered(): bool
    {
        $filters = $this->getSessionValue('filter');

        return null !== $filters && count($filters) > 0;
    }
}
