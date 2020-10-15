<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;

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

    protected $doctrine;
    protected $request_stack;
    protected $session;
    protected $form_factory;
    protected $router;

    /**
     * The query builder that filter the results.
     *
     * @var QueryBuilder
     */
    protected $qb;

    /**
     * @var FilterObject
     */
    protected $filter;

    /**
     * @var array
     */
    protected $filters = [];

    /**
     * @var array
     */
    protected $sorts = [];

    /**
     * @var array
     */
    protected $allowedSorts = [];

    /**
     * @var array
     */
    protected $defaultFilters = [];

    /**
     * @var array
     */
    protected $defaultSorts = [];

    /**
     * Results of the query (in fact this is a Paginator object which contains
     * the result set and some methods to display pager and extra things).
     */
    protected $results;

    /**
     * Number of result(s) to display per page.
     *
     * @var int
     */
    protected $maxPerPage;

    /**
     * Default number of result(s) to display per page.
     *
     * @var int
     */
    protected $defaultMaxPerPage = 30;

    protected $nbResults;

    protected $nbPages;

    /**
     * Options that you can use in your Datagrid methods if you need.
     *
     * @var int
     */
    protected $options;

    /**
     * @var string
     */
    protected $select;

    protected $id;

    /**
     * @var string
     */
    protected $groupBy;

    protected $exports;

    protected $params;

    /**
     * The manager name used for queries.
     * Null is the perfect value if only one manager is used.
     *
     * @var string
     */
    protected $managerName = null;

    public function __construct($doctrine, $request_stack, $session, $form_factory, $router, $name, $params = [])
    {
        $this->doctrine = $doctrine;
        $this->request_stack = $request_stack;
        $this->session = $session;
        $this->form_factory = $form_factory;
        $this->router = $router;
        $this->name = $name;
        $this->params = $params;
    }

    public function create($name, $params = [])
    {
        $this->name = $name;
        $this->name = $params;
    }

    public function execute()
    {
        $this->check();
        $this->buildForm();
        $this->controller();

        return $this;
    }

    protected function check()
    {
        return true;
    }

    public function reset()
    {
        return $this
            ->resetFilters()
            ->resetSort()
            ->resetPage();
    }

    private function controller()
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

    public function setManagerName($name)
    {
        $this->managerName = $name;

        return $this;
    }

    private function getManager()
    {
        return $this->doctrine->getManager($this->managerName);
    }

    private function isRequestedDatagrid()
    {
        return $this->getRequestedDatagrid() == $this->name;
    }

    private function isRequestedAction($action)
    {
        return $this->getRequest()->get(self::ACTION) == $action;
    }

    private function getSessionValue($name, $default = null)
    {
        return $this->getRequest()
            ->getSession()
            ->get($this->getSessionName().'.'.$name, $default);
    }

    private function setSessionValue($name, $value)
    {
        return $this->getRequest()
            ->getSession()
            ->set($this->getSessionName().'.'.$name, $value);
    }

    private function removeSessionValue($name)
    {
        return $this->getRequest()
            ->getSession()
            ->remove($this->getSessionName().'.'.$name);
    }

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

    public function select($select)
    {
        if (is_array($select)) {
            $select = implode(', ', $select);
        }
        $this->select = $select;

        return $this;
    }

    public function groupBy($groupBy)
    {
        if (is_array($groupBy)) {
            $groupBy = implode(', ', $groupBy);
        }
        $this->groupBy = $groupBy;

        return $this;
    }

    public function query($callback)
    {
        $this->qb = $this->getManager()->createQueryBuilder();

        $this->qb = call_user_func($callback, $this->qb);

        return $this;
    }

    public function filter($name, $type, $options, $callback)
    {
        $this->filters[$name] = [
            'type' => $type,
            'options' => $options,
            'query' => $callback,
        ];

        return $this;
    }

    public function sort($column, $order = 'asc')
    {
        $this->sorts[$column] = $order;

        return $this;
    }

    public function id($id)
    {
        $this->id = $id;

        return $this;
    }

    /*********************************/
    /** Filter features here *********/
    /*********************************/

    private function doFilter()
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

    private function applyFilter($data)
    {
        $qb = $this->qb;
        foreach ($data as $key => $value) {
            if (!isset($this->filters[$key]['query'])) {
                throw new \Exception("There is no filter method defined for the field '{$key}'.");
            }
            if ($value) {
                $qb = call_user_func_array($this->filters[$key]['query'], [$value, $qb]);
            }
        }
        $this->setQueryBuilder($qb);
    }

    private function buildForm()
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

    public function setFilterValue($name, $value)
    {
        $filters = $this->getSessionValue('filter', []);
        $filters[$name] = $value;
        $this->setSessionValue('filter', $filters);
    }

    public function getDefaultFilters()
    {
        return $this->defaultFilters;
    }

    public function setAllowedSorts($allowedSorts)
    {
        $this->allowedSorts = $allowedSorts;

        return $this;
    }

    public function setDefaultFilters($defaultFilters)
    {
        $this->defaultFilters = $defaultFilters;

        return $this;
    }

    public function resetFilters()
    {
        $this->removeSessionValue('filter');

        return $this;
    }

    private function getSessionFilter($default = [])
    {
        return $this->getSessionValue('filter', $default);
    }

    private function setSessionFilter($value)
    {
        $this->setSessionValue('filter', $value);

        return $this;
    }

    /**
     * Shortcut.
     */
    public function getFilterFormView()
    {
        return $this->filter->getForm()->createView();
    }

    /*public function configureFilterForm()
    {
        return array();
    }*/

    public function configureFilterBuilder($builder)
    {
        /*
         * Do what you want with the builder.
         * For example, add Event Listener PRE/POST_SET_DATA, etc.
         */
        return;
    }

    public function getAllowedFilterMethods()
    {
        return ['post'];
    }

    /*********************************/
    /** Sort features here ***********/
    /*********************************/

    public function setDefaultSort($defaultSort)
    {
        $this->defaultSorts = $defaultSort;

        return $this;
    }

    protected function doSort()
    {
        $sort = $this->getSessionValue('sort', $this->defaultSorts);

        foreach ($sort as $column => $order) {
            $this->getQueryBuilder()->addOrderBy($column, $order);
        }
    }

    public function updateSort()
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

    public function removeSort()
    {
        $sort = $this->getSessionValue('sort', $this->defaultSorts);
        unset($sort[$this->getRequestedSortedColumnRemoval()]);
        $this->setSessionValue('sort', $sort);
    }

    public function isSortedColumn($column)
    {
        $sort = $this->getSessionValue('sort', $this->defaultSorts);

        return isset($sort[$column]);
    }

    public function getSortedColumnOrder($column)
    {
        $sort = $this->getSessionValue('sort', $this->defaultSorts);

        return $sort[$column];
    }

    public function getSortedColumnPriority($column)
    {
        $sort = $this->getSessionValue('sort', $this->defaultSorts);

        return array_search($column, array_keys($sort));
    }

    public function getSortCount()
    {
        $sort = $this->getSessionValue('sort', $this->defaultSorts);

        return count($sort);
    }

    public function resetSort()
    {
        $this->removeSessionValue('sort');

        return $this;
    }

    /*********************************/
    /** Export features here *********/
    /*********************************/

    /**
     * @return self
     */
    public function export($name, $params = [])
    {
        $class = $this->getExport($name);
        $this->buildForm();
        $this->doSort();
        $this->doFilter();

        $qb = $this->getQueryBuilder()->select($this->select);

        $export = new $class($qb, $params);

        return $export->execute();
    }

    protected function getExport($name)
    {
        $exports = $this->getExports();
        if (!isset($exports[$name])) {
            throw new \Exception('The "'.$name.'" export doesn\'t exist in this datagrid.');
        }

        return $exports[$name];
    }

    public function setExports($exports)
    {
        $this->exports = $exports;

        return $this;
    }

    protected function getExports()
    {
        return $this->exports;
    }

    public function getSessionName()
    {
        return 'datagrid.'.$this->name;
    }

    protected function updatePage()
    {
        $this->setSessionValue('page', $this->getRequestedPage(1));

        return $this;
    }

    protected function resetPage()
    {
        $this->removeSessionValue('page');

        return $this;
    }

    public function getCurrentPage()
    {
        return $this->getSessionValue('page', 1);
    }

    public function setCurrentPage($page)
    {
        return $this->setSessionValue('page', $page);
    }

    public function getNbResults()
    {
        return $this->nbResults;
    }

    public function getNbPages()
    {
        return $this->nbPages;
    }

    public function getAllResults()
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

    private function removeColumn()
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

    private function addColumn()
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

    public function getDefaultColumns()
    {
        return [];
    }

    public function getNonRemovableColumns()
    {
        return [];
    }

    public function getAppendableColumns()
    {
        return [];
    }

    public function getAvailableAppendableColumns()
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

    private function limit()
    {
        $limit = $this->getRequestedLimit();

        if (in_array($limit, $this->getAvailableMaxPerPage())) {
            $this->setSessionValue('limit', $limit);
        }
    }

    public function getAvailableMaxPerPage()
    {
        return [15, 30, 50];
    }

    public function getDefaultMaxPerPage()
    {
        return $this->defaultMaxPerPage;
    }

    public function setDefaultMaxPerPage($maxPerPage)
    {
        $this->defaultMaxPerPage = $maxPerPage;

        return $this;
    }

    public function getMaxPerPage()
    {
        return $this->getSessionValue('limit', $this->getDefaultMaxPerPage());
    }

    public function setMaxPerPage($value)
    {
        $this->setSessionValue('limit', $value);

        return $this;
    }

    /*********************************/
    /** Routing helper methods here **/
    /*********************************/

    protected function getRequestedAction($default = null)
    {
        return $this->getRequest()->get(self::ACTION, $default);
    }

    protected function getRequestedDatagrid($default = null)
    {
        return $this->getRequest()->get(self::ACTION_DATAGRID, $default);
    }

    protected function getRequestedSortColumn($default = null)
    {
        $requested = $this->getRequest()->get(self::PARAM1, $default);

        // if there is a whitelist, ignore everything that is not in it
        if ($this->allowedSorts && !in_array($requested, $this->allowedSorts)) {
            $requested = null;
        }

        return $requested ?? $default;
    }

    protected function getRequestedSortOrder($default = null)
    {
        $requested = strtolower($this->getRequest()->get(self::PARAM2, $default));

        // if there is a whitelist, ignore everything that is not in it
        if (!in_array($requested, ['asc', 'desc'])) {
            $requested = null;
        }

        return $requested ?? $default;
    }

    protected function getRequestedSortedColumnRemoval($default = null)
    {
        return $this->getRequest()->get(self::PARAM1, $default);
    }

    protected function getRequestedPage($default = null)
    {
        $page = $this->getRequest()->get(self::PARAM1, $default);

        return is_numeric($page) ? $page : $default;
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

    protected function getRequestedLimit($default = null)
    {
        return $this->getRequest()->get(self::PARAM1, $default);
    }

    /*********************************/
    /** Global service shortcuts *****/
    /*********************************/

    /**
     * Shortcut to return the request service.
     *
     * @return \Symfony\Component\HttpFoundation\Request
     */
    protected function getRequest()
    {
        return $this->request_stack->getCurrentRequest();
    }

    /**
     * Shortcut to return the request service.
     *
     * @return \Symfony\Component\HttpFoundation\Request
     */
    protected function getSession()
    {
        return $this->session;
    }

    /**
     * return the Form Factory Service.
     *
     * @return \Symfony\Component\Form\FormFactory
     */
    protected function getFormFactory()
    {
        return $this->form_factory;
    }

    public function getQueryBuilder()
    {
        return $this->qb;
    }

    public function setQueryBuilder($qb)
    {
        $this->qb = $qb;

        return $this;
    }

    public function getResults()
    {
        return $this->results;
    }

    public function getDefaultSortOrder()
    {
        return 'ASC';
    }

    /**
     * Generate pagination route.
     *
     * @return string
     */
    public function getPaginationPath($route, $page, $extraParams = [])
    {
        $params = [
            self::ACTION => self::ACTION_PAGE,
            self::ACTION_DATAGRID => $this->name,
            self::PARAM1 => $page,
        ];

        return $this->router
            ->generate($route, array_merge($params, $extraParams));
    }

    /**
     * Generate reset route for the button view.
     *
     * @return string
     */
    public function getResetPath($route, $extraParams = [])
    {
        $params = [
            self::ACTION => self::ACTION_RESET,
            self::ACTION_DATAGRID => $this->name,
        ];

        return $this->router
            ->generate($route, array_merge($params, $extraParams));
    }

    /**
     * Generate sorting route for a given column to be displayed in view.
     *
     * @todo Remove the order parameter and ask to the datagrid to guess it ?
     *
     * @return string
     */
    public function getSortPath($route, $column, $order, $extraParams = [])
    {
        $params = [
            self::ACTION => self::ACTION_SORT,
            self::ACTION_DATAGRID => $this->name,
            self::PARAM1 => $column,
            self::PARAM2 => $order,
        ];

        return $this->router
            ->generate($route, array_merge($params, $extraParams));
    }

    /**
     * Generate remove sort route for a given column to be displayed in view.
     *
     * @return string
     */
    public function getRemoveSortPath($route, $column, $extraParams = [])
    {
        $params = [
            self::ACTION => self::ACTION_REMOVE_SORT,
            self::ACTION_DATAGRID => $this->name,
            self::PARAM1 => $column,
        ];

        return $this->router
            ->generate($route, array_merge($params, $extraParams));
    }

    /**
     * Generate new column route for a given column to be displayed in view.
     *
     * @return string
     */
    public function getNewColumnPath($route, $newColumn, $precedingColumn, $extraParams = [])
    {
        $params = [
            self::ACTION => self::ACTION_ADD_COLUMN,
            self::ACTION_DATAGRID => $this->name,
            self::PARAM1 => $newColumn,
            self::PARAM2 => $precedingColumn,
        ];

        return $this->router
            ->generate($route, array_merge($params, $extraParams));
    }

    /**
     * Generate remove column route for a given column to be displayed in view.
     *
     * @return string
     */
    public function getRemoveColumnPath($route, $column, $extraParams = [])
    {
        $params = [
            self::ACTION => self::ACTION_REMOVE_COLUMN,
            self::ACTION_DATAGRID => $this->name,
            self::PARAM1 => $column,
        ];

        return $this->router
            ->generate($route, array_merge($params, $extraParams));
    }

    /**
     * Generate max per page route to be displayed in view.
     *
     * @return string
     */
    public function getMaxPerPagePath($route, $limit, $extraParams = [])
    {
        $params = [
            self::ACTION => self::ACTION_LIMIT,
            self::ACTION_DATAGRID => $this->name,
            self::PARAM1 => $limit,
        ];

        return $this->router
            ->generate($route, array_merge($params, $extraParams));
    }

    public function getBatchData()
    {
        return (array) json_decode($this->getRequest()->cookies->get($this->name.'_batch'));
    }

    public function isBatchChecked($identifier)
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

    public function hasAllCheckedBatch()
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

    public function hasCheckedBatch()
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

    public function isFiltered()
    {
        $filters = $this->getSessionValue('filter');
        return null !== $filters && count($filters) > 0;
    }
}
