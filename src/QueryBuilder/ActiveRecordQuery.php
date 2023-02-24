<?php

namespace Nueaf\Uteeni\QueryBuilder;

use Exception;
use Nueaf\Uteeni\Database;
use Nueaf\Uteeni\SqlSyntaxor;
use PDO;

/**
 * @method ActiveRecordQuery filterBy($col, $value, $operator="=") {
 * @method ActiveRecordQuery filterByOr(ActiveRecordQueryFilter $part1, ActiveRecordQueryFilter $part2) {
 * @method ActiveRecordQuery filterByAnd(ActiveRecordQueryFilter $part1, ActiveRecordQueryFilter $part2) {
 * @method ActiveRecordQuery filterBySubQuery($col, ActiveRecordSubQuery $subquery, $subqueryCol, $aggregate="MAX", $operator="=") {
 * @method ActiveRecordQuery filterByIn($col, array $value, $operator="IN") {
 * @method ActiveRecordQuery filterByInSubQuery($col, ActiveRecordSubQuery $subquery, $subqueryCol, $operator="IN") {
 * @method ActiveRecordQuery filterByMatch($col1, $col2, $operator="=") {
 */
class ActiveRecordQuery {
    /**
     * Indicates Ascending sorting
     */
    CONST SORT_ASC = "ASC";

    /**
     * Indicates descending sorting
     */
    CONST SORT_DESC = "DESC";

    CONST JOIN_INNER = "INNER";

    CONST JOIN_LEFT = "LEFT";

    protected $obj;
    protected $aliases = Array();
    protected $mainAlias = null;
    protected $sorts = Array();
    protected $limit = 0;
    protected $offset = 0;
    protected $groups = Array();
    protected $aggregates = Array();
	protected $calculatedColumns = Array();
    protected $joins = Array();
    protected $filters = Array();
	protected $fullJoin = false;

    /**
     * Constructor for the query object.
     *
     * The constuctor expects to known the table or view model to select from.
     *
     * @param string $model The name of the model class to be used as base for the query
     * @throws Exception
     * @throws Exception
     */
    public function __construct($model) {
        if (!class_exists($model)) {
            throw new Exception("Could not find class for model: $model");
        }

        if (!is_subclass_of($model, "ActiveRecord")) {
            throw new Exception("Class is not an ActiveRecord class: $model");
        }

        $this->obj = new $model();
        $this->mainAlias = $this->createAlias($model);

        //Set up default sorting
        $this->setSort();
    }

	public static function reverseJoin($class, $join) {
		$trimmedJoin = trim($join, ".");
		$remainingJoin = null;
		if (strpos($trimmedJoin, ".")) list($firstJoin, $remainingJoin) = explode(".", $trimmedJoin, 2);
		else $firstJoin = $trimmedJoin;

		$obj = new $class();
		$reversed = $obj->reversedJoin($firstJoin);

		$result = "";
		if ($remainingJoin) {
			$result = self::reverseJoin($reversed["class"], $remainingJoin) . "uteeni";
		}
		$result .= $reversed["assoc"];

		if (substr($join,0,1)==".") $result = ".$result";
		if (substr($join,-1,1)==".") $result = "$result.";

		return $result;
	}

    protected function createAlias($modelName) {
        $alias_base = strtolower(preg_replace('/[a-z]/', '', $modelName));
        if ($alias_base == "") {
            $alias_base = $modelName[0];
        }

        $alias = $alias_base;
        $number = 0;
        while ($this->getAlias($alias)) {
            $number++;
            $alias = $alias_base . $number;
        }

        $this->aliases[$alias] = $modelName;

        return $alias;
    }

    public function getMainAlias() {
        return $this->mainAlias;
    }

    protected function getJoinedAliases($fromAlias=null, $joinType=null) {
		if($joinType === null){
			return $this->aliases;
		}

        if ($fromAlias==null) {
            $fromAlias = $this->mainAlias;
        }

        $aliases = Array($fromAlias => $this->getAlias($fromAlias));

        if (array_key_exists($fromAlias, $this->joins)) {
            foreach ($this->joins[$fromAlias] as $fromAssoc => $joinInfos) {
                foreach ($joinInfos as $joinInfo) {
                    if ($joinType==null || $joinType==$joinInfo["assoc_type"]) {
                        $newAliases = $this->getJoinedAliases($joinInfo["remoteAlias"], $joinType);
                        $aliases = array_merge($aliases, $newAliases);
                    }
                }
            }
        }

        return $aliases;
    }

    protected function getAliasColumns($alias=null, $joinType=null) {
        $result = Array();
        foreach ($this->getJoinedAliases($alias, $joinType) as $alias => $model) {
            $aliasColumns = Array();
            $obj = new $model();
            foreach ($obj->getProperties() as $name => $meta) {
                $aliasColumns[$name] = "$alias.$name";
            }
            $result[$alias] = $aliasColumns;
        }
        return $result;
    }

    public function hasAlias($alias) {
        return array_key_exists($alias, $this->aliases);
    }

    public function getAlias($alias) {
        if (!$this->hasAlias($alias)) return null;
        return $this->aliases[$alias];
    }

	public function addCalculatedColumn($expression, $name, $alias=null) {
		$tokens = Array(
			"COLUMN"         => '([a-zA-Z0-9_]+\.)*[a-zA-Z0-9_]+',
			"ADDITION"		 => '\+',
			"SUBTRACTION"	 => '\-',
			"MULTIPLICATION" => '\*',
			"DIVISION"        => '\/',
			"PAR_START"      => '\(',
			"PAR_END"        => '\)',
			"FUNC_SUM"		=> 'SUM',
			"FUNC_COUNT"	=> 'COUNT',
			"FUNC_CONCAT"	=> 'CONCAT',
			"FUNC_IF"		=> 'IF',
			"COMMA"			=> ",",
			"STR_START"		=> "\'",
			"STR_END"		=> "\'",
			"CHAR"			=> "(.[^'])*.",
			"ENDLINE"        => '$',
			"EQUAL"			=> "=",
			"LT"			=> "<", 
			"GT"			=> ">", 
			"DIFF1"			=> "<>",
			"DIFF2"			=> "!=",
			"FUNC_INET_NTOA"=> "INET_NTOA",
			"FUNC_INET_ATON"=> "INET_ATON",
		);

		$expression_map = Array(
			"FULL_EXPRESSION" => Array("EXPRESSION,ENDLINE"),
			"EXPRESSION_NO_COMP" => Array("PAR_START,EXPRESSION,PAR_END","CALCULATION","FUNC,PAR_START,EXPRESSIONS,PAR_END","STRING","COLUMN"),
			"EXPRESSION"      => Array("COMPARISION","EXPRESSION_NO_COMP"),
			"COMPARISION"	  => Array("EXPRESSION_NO_COMP,COMP_OPERATOR,EXPRESSION_NO_COMP"),
			"EXPRESSIONS"	  => Array("EXPRESSION,COMMA,EXPRESSIONS", "EXPRESSION"),
			"CALCULATION"     => Array("COLUMN,MATH,EXPRESSION"),
			"MATH"            => Array("ADDITION","SUBTRACTION","MULTIPLICATION","DIVISION"),
			//"AGGR_FUNC"		  => Array("FUNC_SUM", "FUNC_COUNT"), //FIXME support for group by statements
			"FUNC"			  => Array("FUNC_CONCAT", "FUNC_IF", "FUNC_INET_NTOA", "FUNC_INET_ATON"),
			"STRING"		  => Array("STR_START,CHAR,STR_END"),
			"COMP_OPERATOR"	  => Array("EQUAL", "LT", "GT", "DIFF1", "DIFF2")
		);

		if (!function_exists("lex")) {
			function lex($expectation,$string, &$tokens, &$expression_map, $extra=null, $depth=0, $callMap=Array()) {
				//Circular bailout
				if (array_key_exists($expectation,$callMap) && $callMap[$expectation]==strlen($string)) return false;
				$callMap[$expectation]=strlen($string);

				if (array_key_exists($expectation, $tokens)) {
					//Simple tokens by regex ;)
					$regex = $tokens[$expectation];
					$matches = Array();
					preg_match("/^$regex/",$string,$matches);
					if (count($matches)==0) return false;
					$match = $matches[0];
					return Array("type"=>$expectation,"match"=>$match);
				} else if (array_key_exists($expectation, $expression_map)) {
					//Language constructs
					$sub_map = $expression_map[$expectation];
					foreach ($sub_map as $items) {
						$matches = Array();
						$match = "";
						$items = explode(",",$items);
						foreach ($items as $item) {
							$data = lex($item, substr($string,strlen($match)), $tokens, $expression_map, $extra, $depth+1, $callMap);
							if ($data===false) break;
							$matches[] = $data;
							$match .= $data["match"];
						}
						if (count($matches)!=count($items)) continue;
						return Array("matches"=>$matches,"match"=>$match,"type"=>$expectation);
					}
					return false;
				} else {
					throw New Exception("Unknown expected type: $expectation");
				}
			}
		}

		$parsed_expression = lex("FULL_EXPRESSION", $expression, $tokens, $expression_map);
		if ($parsed_expression===False) throw new Exception("Could not parse the expression: $expression");

		if (!function_exists("buildExpression")) {
			function buildExpression($expression, &$extra) {
				switch ($expression["type"]) {
					case "COLUMN":
						$translation = $extra['query']->translateColumnName($expression["match"], $extra['alias']);
						$extra["columns"][] = $translation;
						return $translation["expression"];
						break;
					default:
						if(isset($expression["matches"])){
							$str = "";
							foreach ($expression["matches"] as $match) {
								$str .= buildExpression($match, $extra);
							}
							return $str;							
						}
						return $expression["match"];
				}
			}
		}

		if ($alias===null) $alias = $this->getMainAlias();
		$extra = Array("query"=>$this,"alias"=>$alias,"columns"=>Array());
		$result = buildExpression($parsed_expression, $extra);
		$firstColumn = isset($extra["columns"][0]) ? $extra["columns"][0] : null;

		$this->calculatedColumns[$name] = Array("expression"=>$result, "alias"=>$alias, "column"=>$firstColumn, "name"=>$name);
	}

    public function translateColumnName($column, $alias=null, $allowSelectColumns = false) {
        //If no alias is given, we need to find the alias
        if ($alias == null) {
            //Find out an alias an return the result with alias set
            if (strpos($column, ".")) {
                list($firstPart, $remainder) = explode(".", $column, 2);

                //See if the first part is an alias
                if ($this->hasAlias($firstPart)) return $this->translateColumnName($remainder, $firstPart, $allowSelectColumns);
            }

			if (array_key_exists($column, $this->calculatedColumns)) {
				$result = Array("name"=>$column, "alias" => $this->calculatedColumns[$column]["column"]["alias"], "column" => $this->calculatedColumns[$column]["column"]["column"]);
				if ($allowSelectColumns) $result["expression"]=$column;
				else $result["expression"]=$this->calculatedColumns[$column]["expression"];
				return $result;
			}

            //Assume it should be the alias of the base table
            return $this->translateColumnName($column, $this->mainAlias, $allowSelectColumns);
        }

        while (strpos($column, ".")) {
            list($ref, $column) = explode(".", $column, 2);
            if (array_key_exists($alias, $this->joins) && array_key_exists($ref, $this->joins[$alias])) {
                if (count($this->joins[$alias][$ref])!=1) {
                    throw new Exception("Association is joined several times. Cant select which to use.");
                }
                $alias = $this->joins[$alias][$ref][0]["remoteAlias"];
            } else {
                $alias = $this->join($ref, $alias);
            }
        }

        $cls = $this->getAlias($alias);
        $obj = new $cls();
        $properties = $obj->getProperties();
        if (!array_key_exists($column, $properties)) {
            if ($allowSelectColumns && (array_key_exists($column, $this->buildAggregateSelect()) || array_key_exists($column, $this->buildNormalSelect()))) {
                //Allow the names of aliases from aggregate columns and grouped values. This can be used for order by, and potentially having clauses
                $alias = null;
            } else {
                throw new Exception("Could not find property $column on alias $alias ($cls)");
            }
        }

		$expression = "$alias.$column";
		if ($alias==null) {
			$expression = $column;
		}

        return Array("alias" => $alias, "column" => $column, "expression"=>$expression, "name"=>$column);
    }

    public function join($association, $alias=null, $type=ActiveRecordQuery::JOIN_INNER, $forceNew=false, ActiveRecordQueryFilter $extraFilter = null, $extraFilterType = "AND") {
        if ($alias == null) {
            $alias = $this->mainAlias;
        }

        while (strpos($association, ".")) {
            list($firstJoin, $association) = explode(".",$association,2);
            $alias = $this->join($firstJoin, $alias, $type, $forceNew);
        }

        $model = $this->getAlias($alias);
        $obj = new $model();
        if (!$obj->hasAssociation($association)) {
            throw new Exception("No association by name '$association' on $alias ($model).");
        }
        $associationInfo = $obj->getAssociationInfo($association);
        if ($associationInfo["ass_type"] == "has_and_belongs_to_many") {
            throw new Exception("Cannot join across Many2Many relations");
        }

        if (!array_key_exists($alias, $this->joins)) {
            $this->joins[$alias] = Array();
        }
        if (!array_key_exists($association, $this->joins[$alias])) {
            $this->joins[$alias][$association] = Array();
        }

        if (!$forceNew) {
            $match = null;
            foreach ($this->joins[$alias][$association] as $join) {
                if ($join["type"] == $type) {
                    if ($match != null) {
                        throw new Exception("Several potential joins for same condition found!");
                    }
                    $match = $join["remoteAlias"];
                }
            }
            if ($match != null) {
                return $match;
            }
        }

        $model = $associationInfo["real_class"];
        $obj = new $model();
        $tableName = $obj->getTableName();

        $newAlias = $this->createAlias($model);

        $assType = $associationInfo["ass_type"];
        if ($assType=="belongs_to") $asstype = "has_one";

        $this->joins[$alias][$association][] = Array(
            "type" => $type,
            "assoc_type"=>$assType,
            "remoteTable" => $tableName,
            "remoteAlias" => $newAlias,
            "remoteProperty" => $associationInfo["class_property"],
            "localAlias" => $alias,
            "localProperty" => $associationInfo["local_property"],
			"extraFilter" => $extraFilter,
			"extraFilterType" => $extraFilterType
        );

        return $newAlias;
    }

	/**
	 * Will make the query eager load all joins and not just has_ones. Beware as this can potentially give you ALOT of data
	 *
	 * send false to turn Off again
	 */
	public function fullJoin($fullJoin = true){
		$this->fullJoin = $fullJoin;
	}

    /**
     * Set the sorting for the query.
     *
     * This method will overwrite all other sortings, which have previously been
     * set on the query.
     *
     * If $column is set to null all sorting options will be removed. In this
     * case a default sorting may be added if the base table holds a
     * default_order property.
     *
     * The $column property is first evaluated during execution, but may be in
     * the forms:
     * - [columnname] The name of the column on the base table
     * - [associationname].[columnname] The name of the column on the associated
     * table. Cascading associations deepers is also allowed. If the associated
     * table is allready joined during execution that join will be used,
     * otherwise a join will be made simply for the ordering process.
     * - [aliasname].[columnname] The name of a column on an aliased table.
     * - [aliasname].[association].[columnname] The name of a column on an
     * association on an aliased table. Same rules apply here as for
     * [associationname].[columnname];
     *
     * @param string $column
     * @param string $direction
     */
    public function setSort($column=null, $direction=ActiveRecordQuery::SORT_ASC) {
        $this->sorts = Array();
        if ($column) {
            $this->sorts[$column] = $direction;
        } else {
            if ($this->obj->default_order) {
                $this->sorts[$this->obj->default_order] = ActiveRecordQuery::SORT_ASC;
            }
        }

        return $this;
    }

    /**
     * Adds a less specific sorting at the end of other sort criteria.
     *
     * In case a column allready known is given the new sorting isn't added, but
     * the direction will be updated to the new direction. this will also be the
     * case of the two sorts [associationname].[columnname] and
     * [aliasname].[columnname] appears to be the same during evaluation.
     *
     * @param string $column
     * @param string $direction
     */
    public function addSort($column=null, $direction=ActiveRecordQuery::SORT_ASC) {
        if ($column) {
            $this->sorts[$column] = $direction;
        }
        return $this;
    }

    public function getSort() {
        if (count($this->sorts) == 0)
            return null;
        $sorts = array_keys($this->sorts);
        return $sorts[0];
    }

    public function setGroup($column=null) {
        $this->groups = Array();
        if ($column) {
            $this->groups[] = $column;
        }

        return $this;
    }

    public function addGroup($column) {
        if ($column) {
            $this->groups[] = $column;
        }

        return $this;
    }

    public function getGroup() {
        if (count($this->groups) == 0) return null;
        return $this->groups[0];
    }

    public function getGroups() {
        return $this->groups;
    }

    public function setAggregate($column=null, $aggregate=null) {
        $this->aggregates = Array();
        if ($column) {
            $this->aggregates[] = Array("values"=>$column, "aggregate"=>$aggregate);
        }

        return $this;
    }

    public function addAggregate($column, $aggregate) {
		$this->aggregates[] = Array("values"=>$column, "aggregate"=>$aggregate);
        return $this;
    }

    public function getAggregates() {
        return $this->aggregates;
    }

    public function getSortDirection() {
        if (count($this->sorts) == 0)
            return null;
        $sorts = array_values($this->sorts);
        return $sorts[0];
    }

    public function getSorts() {
        return $this->sorts;
    }

    protected function translateSorts() {
        $orderTranslations = Array();
        foreach ($this->sorts as $col => $direction) {
            $col = $this->translateColumnName($col, null, true);
			$orderTranslations[$col["expression"]] = $direction;
        }

        $order = Array();
        foreach ($orderTranslations as $col => $direction) {
            $order[] = "$col $direction";
        }

        return $order;
    }

    protected function translateGroups() {
        $groupTranslations = Array();
        foreach ($this->groups as $group) {
            $col = $this->translateColumnName($group);
            $groupTranslations[$group] = $col["expression"];
        }

        return $groupTranslations;
    }

    protected function translateAggregates() {
        $aggregateTranslations = Array();
        foreach ($this->aggregates as $index => $aggregateInfo) {

			if($aggregateInfo["values"]){
				$col = $this->translateColumnName($aggregateInfo["values"]);
				$name = strtolower($aggregateInfo["aggregate"])."_".str_replace(".", "_", $aggregateInfo["values"]);
				switch (strtoupper($aggregateInfo["aggregate"])) {
					case "MIN_DISTINCT":
						$aggregateTranslations[$name] = "MIN(DISTINCT {$col["expression"]}) AS $name";
						break;
					case "MAX_DISTINCT":
						$aggregateTranslations[$name] = "MAX(DISTINCT {$col["expression"]}) AS $name";
						break;
					case "GROUP_CONCAT_DISTINCT":
						$aggregateTranslations[$name] = "GROUP_CONCAT(DISTINCT {$col["expression"]}) AS $name";
						break;
					case "COUNT_DISTINCT":
						$aggregateTranslations[$name] = "COUNT(DISTINCT {$col["expression"]}) AS $name";
						break;
					default:
						$aggregateTranslations[$name] = strtoupper($aggregateInfo["aggregate"])."({$col["expression"]}) AS $name";
				}
			} else {
				$aggregateTranslations["agg" . $index] = $aggregateInfo["aggregate"];
			}
        }

        return $aggregateTranslations;
    }

    public function setLimit($limit) {
        $this->limit = intval($limit);
        return $this;
    }

    public function getLimit() {
        return $this->limit;
    }

    public function setOffset($offset) {
        $this->offset = intval($offset);
        return $this;
    }

    public function getOffset() {
        return $this->offset;
    }

    public function __call($name, $args) {
        if (method_exists($this, "get".ucfirst($name))) {
            return $this->addFilter(call_user_func_array(array($this, "get".ucfirst($name)), $args));
        } else {
            throw new Exception("Unknown method: $name");
        }
    }

    public function getFilterBy($col, $value, $operator="=") {
        $col = $this->translateColumnName($col);
        return new ActiveRecordQueryFilterColumnValue($this, $col, $value, $operator);
    }

    public function getFilterByOr() {
        $args = func_get_args();
        return new ActiveRecordQueryFilterOr($args);
    }

    public function getFilterByAnd() {
        $args = func_get_args();
        return new ActiveRecordQueryFilterAnd($args);
    }

    public function getFilterBySubQuery($col, ActiveRecordSubQuery $subquery, $subqueryCol, $aggregate="MAX", $operator="=") {
        $col = $this->translateColumnName($col);
        return new ActiveRecordQueryFilterColumnValueSubquery($this, $col, $subquery, $subqueryCol, $aggregate, $operator);
    }

    public function getFilterBySubQueryMatch($value, ActiveRecordSubQuery $subquery, $subqueryCol, $aggregate="MAX", $operator="=") {
        return new ActiveRecordQueryFilterColumnValueSubqueryMatch($this, $value, $subquery, $subqueryCol, $aggregate, $operator);
    }

    public function getFilterByIn($col, array $value, $operator="IN") {
        $col = $this->translateColumnName($col);
        return new ActiveRecordQueryFilterColumnValueIn($this, $col, $value, $operator);
    }

    public function getFilterByInSubQuery($col, ActiveRecordSubQuery $subquery, $subqueryCol, $operator="IN") {
        $col = $this->translateColumnName($col);
        return new ActiveRecordQueryFilterColumnValueInSubquery($this, $col, $subquery, $subqueryCol, $operator);
    }

    public function getFilterByMatch($col1, $col2, $operator="=") {
        $col1 = $this->translateColumnName($col1);
        $col2 = $this->translateColumnName($col2);

        return new ActiveRecordQueryFilterColumnMatch($this, $col1, $col2, $operator);
    }

    public function addFilter(ActiveRecordQueryFilter $filter) {
        $this->filters[] = $filter;
        return $this;
    }

    public function addSubQuery($model) {
        return new ActiveRecordSubQuery($this, $model);
    }

    public function get_count() {
        $order = $this->translateSorts(); //This may result in more joined tables and hence a different count
        $group = $this->translateGroups(); //This may result in more joined tables and hence a different count
        $select = Array("COUNT(1) AS cnt");

        $result = $this->executeQuery($select, $group);

        if (count($group)) {
            return $result->rowCount();
        } else {
            $count = $result->fetchColumn();
        }

		return $count;
    }

    protected function buildNormalSelect($aliasColumns=null) {
        if (!$aliasColumns) $aliasColumns = $this->getAliasColumns();

        $select = Array();
        foreach ($aliasColumns as $alias => $columns) {
            foreach ($columns as $realName => $alias) {
                $name = str_replace(".", "_", $alias);
                $select[$name] = "$alias AS " . $name;
            }
        }
		foreach ($this->calculatedColumns as $name=>$calculatedColumn) {
			if (array_key_exists($name, $select)) throw new Exception("Alias used twice in select: '$name'");
			$select[$name] = $calculatedColumn["expression"]." AS $name";
		}

        return $select;
    }

    protected function buildAggregateSelect() {
        $select = Array();

        foreach ($this->translateAggregates() as $name=>$selectColumn) {
            $select[$name] = $selectColumn;
        }
        foreach ($this->translateGroups() as $name=>$groupColumn) {
            $name = str_replace(".","_",$name);
            $select[$name] = $groupColumn." AS ".$name;
        }

        return $select;
    }

    protected function buildJoins() {
        $joins = Array();

        foreach ($this->joins as $fromAlias => $joinAssocs) {
            foreach ($joinAssocs as $fromAssoc => $joinInfos) {
                foreach ($joinInfos as $joinInfo) {
                    $model = $this->getAlias($joinInfo["remoteAlias"]);
					$join = "{$joinInfo["type"]} JOIN {$joinInfo["remoteTable"]} AS {$joinInfo["remoteAlias"]} ON {$joinInfo["remoteAlias"]}.{$joinInfo["remoteProperty"]}={$joinInfo["localAlias"]}.{$joinInfo["localProperty"]}";
					$join .= $joinInfo["extraFilter"] ? " {$joinInfo['extraFilterType']} {$joinInfo['extraFilter']}" : "";
					
                    $joins[] = $join;
                }
            }
        }

        return $joins;
    }

    /**
     * Only has_one associations are linked by this method, as has_many may be
     * halfed by a limit elsewhere in the query.
     *
     * @param string $indexBy The name of the field or property to index the result by
     * @return array
     */
    public function execute($indexBy=null, $dontHydrate = false) {
        $groups = $this->translateGroups();
        if (count($groups)) {
            $result = $this->executeGrouped($groups);
        } else {
            $result = $this->executeNormal($dontHydrate);
        }

        if ($indexBy) {
            $keys = array_map(create_function('$item', 'return is_array($item)?$item["'.$indexBy.'"]:$item->'.$indexBy.';'), $result);
            if (count($keys)) $result = array_combine($keys, $result);
        }

        return $result;
    }

    protected function executeGrouped($groups) {
        $order = $this->translateSorts();
        $select = $this->buildAggregateSelect();
        $handle = $this->executeQuery($select, $groups, $order, $this->limit, $this->offset);

        $result = Array();
        while (($row = $handle->fetch(PDO::FETCH_OBJ))) {
            $result[] = $row;
        }
        return $result;
    }

    protected function executeNormal($dontHydrate) {
        $order = $this->translateSorts();
		if($this->fullJoin){
			$aliasColumns = $this->getAliasColumns($this->mainAlias);
		}else{
			$aliasColumns = $this->getAliasColumns($this->mainAlias, "has_one");
		}
        $select = $this->buildNormalSelect($aliasColumns);
		
        $foundEntities = Array();
        foreach ($aliasColumns as $alias => $columns) {
            $foundEntities[$this->getAlias($alias)] = Array();
        }

		$calcualtedColumns = Array();
		foreach ($this->calculatedColumns as $calcualtedColumn) {
			if (!array_key_exists($calcualtedColumn["alias"], $calcualtedColumns)) {
				$calcualtedColumns[$calcualtedColumn["alias"]] = Array();
			}
			$calcualtedColumns[$calcualtedColumn["alias"]][] = $calcualtedColumn["name"];
		}

        $result = Array();
        $handle = $this->executeQuery($select, null, $order, $this->limit, $this->offset);
        while (($row = $handle->fetch(PDO::FETCH_ASSOC))) {
	
            $aliasElements = Array();
			
            foreach ($aliasColumns as $alias => $columns) {
                $model = $this->getAlias($alias);
                $fields = Array();
                foreach ($columns as $realName => $aliasColumn) {
                    $fields[$realName] = $row[str_replace(".", "_", $aliasColumn)];
                }
				if (array_key_exists($alias, $calcualtedColumns)) {
					foreach ($calcualtedColumns[$alias] as $calcualtedColumn) {
						$fields[$calcualtedColumn] = $row[$calcualtedColumn];
					}
				}

                $tmp = new $model();

                $priHash = Array();
                foreach ($tmp->find_primaries() as $primary) {
                    $priHash[] = $fields[$primary];
                }
                $priHash = serialize($priHash);
                if (array_key_exists($priHash, $foundEntities[$model])) {
                    $tmp = $foundEntities[$model][$priHash];
                } else {
					if($dontHydrate) {
						$tmp = (object)$fields; 
					}else{
						$tmp->hydrate($fields);
					}
                    $foundEntities[$model][$priHash] = $tmp;

                    if ($alias == $this->mainAlias) {
                        $result[] = $tmp;
                    }
                }

                //Add the element to aliasElement if it held any values. Ignore missing left joins
                if (count(array_filter($fields))==0) continue;
                $aliasElements[$alias] = $tmp;
            }

            //Take care of the relations between the joined elements
            $this->addJoinData($this->mainAlias, $aliasElements);
        }

        return $result;
    }

    protected function addJoinData($alias, &$aliasElements) {
        if (!array_key_exists($alias, $this->joins)) return false;

        foreach ($this->joins[$alias] as $association=>$infos) {
            $aliases = Array();
            //Get inner joins first (better chance for them holding data ;)
            foreach ($infos as $info) {
                if ( ( $this->fullJoin || $info["assoc_type"]=="has_one" ) && $info["type"]==self::JOIN_INNER) {
					if($info["assoc_type"]=="has_one"){
						$aliases[$association] = $info["remoteAlias"];
					}  else {
						$aliases[$association][] = $info["remoteAlias"];
					}
                }
            }
            foreach ($infos as $info) {
                if ( ( $this->fullJoin || $info["assoc_type"]=="has_one" ) && $info["type"]==self::JOIN_LEFT) {
                    if($info["assoc_type"]=="has_one"){
						$aliases[$association] = $info["remoteAlias"];
					}  else {
						$aliases[$association][] = $info["remoteAlias"];
					}
                }
            }

            foreach ($aliases as $association=>$referred_alias) {
				if(is_array($referred_alias)){
					foreach($referred_alias as $ref_alias){
						if(isset($aliasElements[$ref_alias])){
							$aliasElements[$alias]->{$association}[] = $aliasElements[$ref_alias];
							$this->addJoinData($ref_alias, $aliasElements);
						}else{
							$aliasElements[$alias]->{$association} = array();
						}
					}
				}else{
					$aliasElements[$alias]->$association = $aliasElements[$referred_alias];
					$this->addJoinData($referred_alias, $aliasElements);
				}
			}
		}

        return true;
    }

    protected function getSql($select, $group=null, $order=null, $limit=null, $offset=null) {
        $alias = $this->mainAlias;
        $table = $this->obj->getTableName();
        $table = "$table AS $alias";

        $joins = $this->buildJoins();

        $options = Array(
            "SELECT" => implode(", ", $select),
            "TABLE" => $table,
            "JOINS" => implode(" ", $joins)
        );
        if ($order) {
            $options["ORDERFIELD"] = implode(", ", $order);
        }
        if ($limit) {
            $options["LIMIT"] = $limit;
        }
        if ($offset) {
            $options["OFFSET"] = $offset;
        }
        if (count($this->filters)) {
            $options["WHERE"] = implode(" AND ", $this->filters);
        }
        if (count($group)) {
            if (!array_key_exists("WHERE", $options)) $options["WHERE"]="1";
            $options["WHERE"] .= " GROUP BY ".implode(",", $group);
        }

        $db = Database::connect($this->obj->getDatabaseName());
        $sql = SQLSyntaxor::getSelectSQL($options, $db->getAttribute(PDO::ATTR_DRIVER_NAME));
		
        return $sql;
    }

    public function __toString() {
        $groups = $this->translateGroups();
        $order = $this->translateSorts();
        if (count($groups)) {
            $select = $this->buildAggregateSelect();
        } else {
            $aliasColumns = $this->getAliasColumns($this->mainAlias, "has_one");
            $select = $this->buildNormalSelect($aliasColumns);
        }

        return $this->getSql($select, $groups, $order, $this->limit, $this->offset);
    }

    public function getSqlForField($field) {
        $groups = $this->translateGroups();
        $order = $this->translateSorts();
        $select = array($field);
        return $this->getSql($select, $groups, $order, $this->limit, $this->offset);
    }

    protected function executeQuery($select, $group=null, $order=null, $limit=null, $offset=null) {
        $sql = $this->getSql($select, $group, $order, $limit, $offset);
        $db = Database::connect($this->obj->getDatabaseName());

        $start = microtime(true);
        $result = $db->query($sql);
        $end = microtime(true);

        if (!$result) {
            if (isset($_SERVER['system_environment']) && $_SERVER['system_environment']=="development" && !headers_sent()) header("AR-QUERY-$start: ".($end*1000-$start*1000)." ms - ".$sql, false);
            throw new Exception("Error executing query: $sql - ".print_r($db->errorInfo(),1));
        }

        if (isset($_SERVER['system_environment']) && $_SERVER['system_environment']=="development" && !headers_sent()) header("AR-QUERY-$start: ".$result->rowCount()." rows - ".($end*1000-$start*1000)." ms - ".$sql, false);

        return $result;
    }

	public function doInsertFromSelect($model, $fieldMap) {
		$group = $this->translateGroups();
		$order = $this->translateSorts();

		$select = Array();
		foreach ($fieldMap as $to=>$from) {
			if ($from===null) {
				$from="NULL";
			} else if (strlen($from)==0 || $from[0]!="'") {
				$from = $this->translateColumnName($from, null, true);
				$from = $from["expression"];
			}
			$select[$to] = $from;
		}

		$model = new $model();

		$selectSql = $this->getSql(array_values($select), $group, $order, $this->limit, $this->offset);
		$sql = "INSERT INTO {$model->table_name} (".implode(",",array_keys($select)).") $selectSql";

		$result = $this->obj->getDatabaseConnection()->query($sql);
		if ($result===false) {
			$result = $this->obj->getDatabaseConnection()->errorInfo();
		}
		return $result;
	}

	public function doUpdateFromSelect($fieldMap) {
		$group = $this->translateGroups();
		$order = Array();

		$updates = Array();
		foreach ($fieldMap as $to=>$from) {
			if ($from===null) {
				$from = "NULL";
			} else if (strlen($from)==0 || $from[0]!="'") {
				$from = $this->translateColumnName($from, null, true);
				$from = $from["expression"];
			}
			$to = $this->translateColumnName($to);
			$to = $to["expression"];

			$updates[] = "$to = $from";
		}

		$selectSql = $this->getSql(Array(), $group, $order, $this->limit, $this->offset);
		$sql = preg_replace("/^SELECT \\* FROM /", "UPDATE ", $selectSql);
		$sql = preg_replace("/ WHERE /", " SET ".implode(", ",$updates)." WHERE ", $sql);

		$result = $this->obj->getDatabaseConnection()->query($sql);
		if ($result===false) {
			$result = $this->obj->getDatabaseConnection()->errorInfo();
		}
		return $result;
	}

	public function doDestroyFromSelect($alias = null) {
		if ($alias==null) $alias = $this->getMainAlias();
		if (!$this->hasAlias($alias)) {
			$alias = $this->join($alias);
		}

		$group = $this->translateGroups();
		$order = Array();
		$select = Array("*");

		$selectSql = $this->getSql(array_values($select), $group, $order, $this->limit, $this->offset);
		$sql = "DELETE $alias.".preg_replace("/^SELECT /", "", $selectSql);

		$result = $this->obj->getDatabaseConnection()->query($sql);
		return $result;
	}
}

class ActiveRecordSubQuery extends ActiveRecordQuery {

    protected $outerQuery;

    public function __construct(ActiveRecordQuery $outerQuery, $model) {
        $this->outerQuery = $outerQuery;
        parent::__construct($model);
    }

    public function createAlias($modelName) {
        return $this->outerQuery->createAlias($modelName);
    }

    public function hasAlias($alias) {
        return $this->outerQuery->hasAlias($alias);
    }

    public function getAlias($alias) {
        return $this->outerQuery->getAlias($alias);
    }

    public function getJoinedAliases($fromAlias = null, $joinType = null) {
        $local = parent::getJoinedAliases($fromAlias, $joinType);
        $outer = $this->outerQuery->getJoinedAliases($fromAlias, $joinType);
        $result = array_merge($outer, $local);
        return $result;
    }
}
