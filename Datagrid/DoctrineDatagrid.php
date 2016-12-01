<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid;

/**
 * Datagrid management class that support and handle pagination, sort, filter
 * and now, export actions.
 * @author Maxime CORSON <maxime.corson@spyrit.net>
 */
class DoctrineDatagrid
{
    const ACTION                = 'action';
    const ACTION_DATAGRID       = 'datagrid';
    const ACTION_PAGE           = 'page';
    const ACTION_SORT           = 'sort';
    const ACTION_REMOVE_SORT    = 'remove-sort';
    const ACTION_RESET          = 'reset';
    const ACTION_LIMIT          = 'limit';
    const ACTION_ADD_COLUMN     = 'add-column';
    const ACTION_REMOVE_COLUMN  = 'remove-column';
    
    const PARAM1 = 'param1';
    const PARAM2 = 'param2';
    
    /**
     * The container witch is usefull to get Request parameters and differents 
     * options and parameters.
     * @var \Symfony\Component\DependencyInjection\Container
     */
    protected $container;
    
    /**
     * The query builder that filter the results
     * @var \Doctrine\ORM\QueryBuilder
     */
    protected $qb;
    
    /**
     * @var FilterObject
     */
    protected $filter;
    
    /**
     *
     * @var array 
     */
    protected $filters = [];
    
    /**
     *
     * @var array 
     */
    protected $sorts = [];
    
    /**
     *
     * @var array 
     */
    protected $defaultSorts = [];
    
    /**
     * Results of the query (in fact this is a PropelPager object which contains
     * the result set and some methods to display pager and extra things)
     * @var \PropelPager
     */
    protected $results;
    
    /**
     * Number of result(s) to display per page 
     * @var integer 
     */
    protected $maxPerPage;
    
    /**
     * Default number of result(s) to display per page 
     * @var integer 
     */
    protected $defaultMaxPerPage = 30;
    
    /**
     *
     * @var type 
     */
    protected $nbResults;
    
    /**
     *
     * @var type 
     */
    protected $nbPages;
    
    /**
     * Options that you can use in your Datagrid methods if you need
     * @var integer 
     */
    protected $options;
    
    /**
     *
     * @var string 
     */
    protected $select;
    
    /**
     *
     * @var type 
     */
    protected $id;
    
    /**
     *
     * @var type 
     */
    protected $exports;
    
    /**
     * 
     * @var params
     */
    protected $params;
    
    /**
     * The manager name used for queries.
     * Null is the perfect value if only one manager is used.
     * @var string 
     */
    protected $managerName = null;

    public function __construct($container, $name, $params = array())
    {
        $this->container = $container;
        $this->name = $name;
        $this->params = $params;
    }
    
    public function create($name, $params = array())
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
        if($this->isRequestedDatagrid())
        {
            switch($this->getRequestedAction())
            {
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
        return $this->container->get('doctrine')->getManager($this->managerName);
    }
    
    private function isRequestedDatagrid()
    {
        return ($this->getRequestedDatagrid() == $this->name);
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
        
        $this->nbPages = ceil($this->nbResults/$this->getMaxPerPage());
        
        //var_dump($this->qb->getEntityManager()->getConnection()->getDatabase()); die();
        
        return $this->qb->select('DISTINCT '.$this->select)
            ->setFirstResult(($this->getCurrentPage()-1) * $this->getMaxPerPage())
            ->setMaxResults($this->getMaxPerPage())
            ->getQuery()->getResult(); 
    }
    
    /*********************************/
    /* Query features ****************/
    /*********************************/
    
    public function select($select)
    {
        if(is_array($select))
        {
            $select = implode(', ', $select);
        }
        $this->select = $select;
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
        $this->filters[$name] = array(
            'type' => $type,
            'options' => $options,
            'query' => $callback,
        );
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
    /* Filter features here **********/
    /*********************************/
    
    private function doFilter()
    {
        if(in_array(
                $this->getRequest()->getMethod(), 
                array_map('strtoupper', $this->getAllowedFilterMethods())
            ) && $this->getRequest()->get($this->filter->getForm()->getName())
        )
        {
            $this->setCurrentPage(1);
            $data = $this->getRequest()->get($this->filter->getForm()->getName());
        }
        else
        {
            $data = $this->getSessionValue('filter', $this->getDefaultFilters());
        }
        
        if($this->filter)
        {
            $this->filter->submit($data);
            $form = $this->filter->getForm();
            $formData = $form->getData();

            if($form->isValid())
            {
                if(in_array(
                    $this->getRequest()->getMethod(), 
                    array_map('strtoupper', $this->getAllowedFilterMethods())
                ))
                {
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
        foreach($data as $key => $value)
        {
            if(!isset($this->filters[$key]['query']))
            {
                throw new \Exception("There is no filter method defined for the field '{$key}'.");
            }
            if($value)
            {
                $qb = call_user_func_array($this->filters[$key]['query'], array($value, $qb));
            }
        }
        $this->setQueryBuilder($qb);
    }
    
    private function buildForm()
    {
        if(!empty($this->filters))
        {
            $this->filter = new FilterObject($this->getFormFactory(), $this->name);
            
            foreach($this->filters as $name => $filter)
            {
                $this->filter->add(
                    $name, 
                    $filter['type'], 
                    isset($filter['options'])? $filter['options'] : array(), 
                    isset($filter['value'])? $filter['value'] : null
                );
            }
            $this->configureFilterBuilder($this->filter->getBuilder());
        }
    }
    
    public function setFilterValue($name, $value)
    {
        $filters = $this->getSessionValue('filter', array());
        $filters[$name] = $value;
        $this->setSessionValue('filter', $filters);
    }
    
    /**
     * @todo A refactorer et rendre acessible depuis le service
     * @return type
     */
    protected function getDefaultFilters()
    {
        return array();
    }
    
    public function resetFilters()
    {
        $this->removeSessionValue('filter');
        return $this;
    }
    
    private function getSessionFilter($default = array())
    {
        return $this->getSessionValue('filter', $default);
    }
    
    private function setSessionFilter($value)
    {
        $this->setSessionValue('filter', $value);
        return $this;
    }
    
    /**
     * Shortcut 
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
        /**
         * Do what you want with the builder. 
         * For example, add Event Listener PRE/POST_SET_DATA, etc.
         */
        return;
    }
    
    public function getAllowedFilterMethods()
    {
        return array('post');
    }
    
    /*********************************/
    /* Sort features here ************/
    /*********************************/
    
    public function setDefaultSort($defaultSort)
    {
        $this->defaultSorts = $defaultSort;
        
        return $this;
    }
    
    protected function doSort()
    {
        $sort = $this->getSessionValue('sort', $this->defaultSorts);
        
        foreach($sort as $column => $order)
        {
            $this->getQueryBuilder()->orderBy($column, $order);
        }
    }
    
    public function updateSort()
    {
        $sort = $this->getSessionValue('sort', $this->defaultSorts);
        if(isset($this->params['multi_sort']) && ($this->params['multi_sort'] == false))
        {
            unset($sort);
        }
        $sort[$this->getRequestedSortColumn()] = $this->getRequestedSortOrder();
        $this->setSessionValue('sort', $sort);
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
    /* Export features here **********/
    /*********************************/
    
    /**
     * @param type $name
     * @param type $params
     * @return self
     */
    public function export($name, $params = array())
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
        if(!isset($exports[$name]))
        {
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
    
    /*********************************/
    /* Dynamic columns feature here **/
    /*********************************/
    
    private function removeColumn()
    {
        $columnToRemove = $this->getRequestedColumnRemoval();
        $columns = $this->getColumns();

        if(array_key_exists($columnToRemove, $columns))
        {
            unset($columns[$columnToRemove]);
            $this->setSessionValue('columns', $columns);
            /**
             * @todo Remove sort on the removed column
             */
        }
    }
    
    private function addColumn()
    {
        $newColumn = $this->getRequestedNewColumn();
        $precedingColumn = $this->getRequestedPrecedingNewColumn();

        if(array_key_exists($newColumn, $this->getAvailableAppendableColumns()))
        {
            $columns = $this->getColumns();
            $newColumnsArray = array();

            foreach($columns as $column => $label)
            {
                $newColumnsArray[$column] = $label;
                if($column == $precedingColumn)
                {
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
        return array();
    }
    
    public function getNonRemovableColumns()
    {
        return array();
    }
    
    public function getAppendableColumns()
    {
        return array();
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
    /* Max per page feature here *****/
    /*********************************/
    
    private function limit()
    {
        $limit = $this->getRequestedLimit();

        if(in_array($limit, $this->getAvailableMaxPerPage()))
        {
            $this->setSessionValue('limit', $limit);
        }
    }
    
    public function getAvailableMaxPerPage()
    {
        return array(15, 30, 50);
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
    /* Routing helper methods here ***/
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
        return $this->getRequest()->get(self::PARAM1, $default);
    }
    
    protected function getRequestedSortOrder($default = null)
    {
        return $this->getRequest()->get(self::PARAM2, $default);
    }
    
    protected function getRequestedSortedColumnRemoval($default = null)
    {
        return $this->getRequest()->get(self::PARAM1, $default);
    }
    
    protected function getRequestedPage($default = null)
    {
        return $this->getRequest()->get(self::PARAM1, $default);
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
    /* Global service shortcuts ******/
    /*********************************/
    
    /**
     * Shortcut to return the request service.
     * @return \Symfony\Component\HttpFoundation\Request
     */
    protected function getRequest()
    {
        return $this->container->get('request_stack')->getCurrentRequest();
    }
    
    /**
     * Shortcut to return the request service.
     * @return \Symfony\Component\HttpFoundation\Request
     */
    protected function getSession()
    {
        return $this->container->get('session');
    }
    
    /**
     * return the Form Factory Service
     * @return \Symfony\Component\Form\FormFactory
     */
    protected function getFormFactory()
    {
        return $this->container->get('form.factory');
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
     * Generate pagination route
     * @param type $route
     * @param type $extraParams
     * @return string
     */
    public function getPaginationPath($route, $page, $extraParams = array())
    {
        $params = array(
            self::ACTION => self::ACTION_PAGE,
            self::ACTION_DATAGRID => $this->name,
            self::PARAM1 => $page,
        );
        return $this->container->get('router')
            ->generate($route, array_merge($params, $extraParams));
    }
    
    /**
     * Generate reset route for the button view
     * @param type $route
     * @param type $extraParams
     * @return string
     */
    public function getResetPath($route, $extraParams = array())
    {
        $params = array(
            self::ACTION => self::ACTION_RESET,
            self::ACTION_DATAGRID => $this->name,
        );
        return $this->container->get('router')
            ->generate($route, array_merge($params, $extraParams));
    }
    
    /**
     * Generate sorting route for a given column to be displayed in view
     * @todo Remove the order parameter and ask to the datagrid to guess it ?
     * @param type $route
     * @param type $column
     * @param type $order
     * @param type $extraParams
     * @return string
     */
    public function getSortPath($route, $column, $order, $extraParams = array())
    {
        $params = array(
            self::ACTION => self::ACTION_SORT,
            self::ACTION_DATAGRID => $this->name,
            self::PARAM1 => $column,
            self::PARAM2 => $order,
        );
        return $this->container->get('router')
            ->generate($route, array_merge($params, $extraParams));
    }
    
    /**
     * Generate remove sort route for a given column to be displayed in view
     * @param type $route
     * @param type $column
     * @param type $extraParams
     * @return string
     */
    public function getRemoveSortPath($route, $column, $extraParams = array())
    {
        $params = array(
            self::ACTION => self::ACTION_REMOVE_SORT,
            self::ACTION_DATAGRID => $this->name,
            self::PARAM1 => $column,
        );
        return $this->container->get('router')
            ->generate($route, array_merge($params, $extraParams));
    }
    
    /**
     * Generate new column route for a given column to be displayed in view
     * @param type $route
     * @param type $column
     * @param type $precedingColumn
     * @param type $extraParams
     * @return type
     */
    public function getNewColumnPath($route, $newColumn, $precedingColumn, $extraParams = array())
    {
        $params = array(
            self::ACTION => self::ACTION_ADD_COLUMN,
            self::ACTION_DATAGRID => $this->name,
            self::PARAM1 => $newColumn,
            self::PARAM2 => $precedingColumn,
        );
        return $this->container->get('router')
            ->generate($route, array_merge($params, $extraParams));
    }
    
    /**
     * Generate remove column route for a given column to be displayed in view
     * @param type $route
     * @param type $column
     * @param type $precedingColumn
     * @param type $extraParams
     * @return type
     */
    public function getRemoveColumnPath($route, $column, $extraParams = array())
    {
        $params = array(
            self::ACTION => self::ACTION_REMOVE_COLUMN,
            self::ACTION_DATAGRID => $this->name,
            self::PARAM1 => $column,
        );
        return $this->container->get('router')
            ->generate($route, array_merge($params, $extraParams));
    }
    
    /**
     * Generate max per page route to be displayed in view
     * @param type $route
     * @param type $column
     * @param type $precedingColumn
     * @param type $extraParams
     * @return type
     */
    public function getMaxPerPagePath($route, $limit, $extraParams = array())
    {
        $params = array(
            self::ACTION => self::ACTION_LIMIT,
            self::ACTION_DATAGRID => $this->name,
            self::PARAM1 => $limit,
        );
        return $this->container->get('router')
            ->generate($route, array_merge($params, $extraParams));
    }
    
    public function getBatchData()
    {
        return (array) json_decode($this->getRequest()->cookies->get($this->name.'_batch'));
    }
    
    public function isBatchChecked($identifier)
    {
        $data = $this->getBatchData();
        if($data)
        {
            if($data['type'] == 'include' && in_array($identifier, $data['checked']))
            {
                return true;
            }
            elseif($data['type'] == 'exclude' && !in_array($identifier, $data['checked']))
            {
                return true;
            }
        }
        return false;
    }
    
    public function hasAllCheckedBatch()
    {
        $data = $this->getBatchData();
        if($data)
        {
            if($data['type'] == 'include' && count($data['checked']) == count($this->getResults()))
            {
                return true;
            }
            elseif($data['type'] == 'exclude' && count($data['checked']) == 0)
            {
                return true;
            }
        }
        return false;
    }
    
    public function hasCheckedBatch()
    {
        $data = $this->getBatchData();
        if($data)
        {
            if($data['type'] == 'include' && count($data['checked']) > 0)
            {
                return true;
            }
            elseif($data['type'] == 'exclude' && count($data['checked']) < count($this->getResults()))
            {
                return true;
            }
        }
        return false;
    }
}
