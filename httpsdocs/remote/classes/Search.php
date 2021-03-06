<?php

namespace CB;

class Search extends SolrClient{
	/*when requested to sort by a field the other convenient sorting field can be used designed for sorting. Used for string fields. */
	var $sortFields = array('name' => 'sort_name', 'path' => 'sort_path');
	
	function query($p){
		$this->results = false;
		$this->inputParams = $p;
		$this->prepareParams();
		$this->connect();
		$this->executeQuery();
		$this->processResult();
		return $this->results;

	}

	private function prepareParams(){
		$p = &$this->inputParams;
		/* initial parameters */
		$this->query = empty($p->query)? '' : $p->query;
		$this->start = empty($p->start)? 0 : intval($p->start);
		$this->rows = empty($p->rows)? \CB\config\max_rows : intval($p->rows);
		
		$fq = array('dstatus:0'); //by default filter not deleted nodes


		$this->params = array(
			'defType' => 'dismax'
			,'q.alt' => '*:*'
			,'qf' => "name content^0.5"
			,'tie' => '0.1'
			,'fl' => "id, pid, path, name, type, subtype, system, size, date, date_end, oid, cid, cdate, uid, udate, case_id, case, template_id, user_ids, status, category_id, importance, completed, versions"
			,'sort' => 'ntsc asc'
		);
		/* initial parameters */
		
		if(!empty($p->dstatus)) $fq = array('dstatus:'.intval($p->dstatus));
		if(!empty($p->fq)) $fq = array_merge($fq, $p->fq);

		/* set custom field list if specified */
		if(!empty($p->fl)) $this->params['fl'] = $p->fl;
		
		/*analize sort parameter (ex: status asc,date_end asc)/**/
		if(isset($p->sort)){
			$sort = array();
			if(!is_array($p->sort)) $sort = array($p->sort => empty($p->dir) ? 'asc' : strtolower($p->dir) );
			else foreach($p->sort as $s){
				$s = explode(' ', $s);
				$sort[$s[0]] = empty($s[1]) ? 'asc' : strtolower($s[1]);
			}
			foreach($sort as $f => $d){
		 		if(!in_array($f, array('name', 'path', 'size', 'date', 'date_end', 'importance', 'completed',  'category_id', 'status', 'oid', 'cid', 'uid', 'cdate', 'udate', 'case'))) continue;
		 		
		 		if(isset($this->sortFields[$f])) $f = $this->sortFields[$f]; // replace with convenient sorting fields if defined
		 		
		 		$this->params['sort'] .= ",$f $d"; 	
		 	}
		}else $this->params['sort'] .= ', sort_name asc';//, subtype asc
		
		/* adding additional query filters */

		/* adding security filter*/
		$everyoneGroup = Security::EveryoneGroupId();
		$fq[] = 'allow_user_ids:('.$everyoneGroup.' OR '.$_SESSION['user']['id'].')';
		//$fq[] = '!deny_user_ids:('.$everyoneGroup.' OR '.$_SESSION['user']['id'].')';
		$fq[] = '!deny_user_ids:'.$_SESSION['user']['id'];
		/* end of adding security filter*/

		if(!empty($p->pid)){
			$ids = Util\toNumericArray($p->pid);
			if(!empty($ids)) $fq[] = 'pid:('.implode(' OR ', $ids).')';
		}
		if(!empty($p->ids)){
			$ids = Util\toNumericArray($p->ids);
			if(!empty($ids)) $fq[] = 'id:('.implode(' OR ', $ids).')';
		} 
		if(!empty($p->pids)){
			$ids = Util\toNumericArray($p->pids);
			if(!empty($ids)) $fq[] = 'pids:('.implode(' OR ', $ids).')';
		} 
		if(!empty($p->types)){
			if(!is_array($p->types)) $p->types = explode(',', $p->types);
			for ($i=0; $i < sizeof($p->types); $i++)
				switch($p->types[$i]){
					case 'folder':	$p->types[$i] = 1; break;
					case 'link':	$p->types[$i] = 2; break;
					case 'case':	$p->types[$i] = 3; break;
					case 'object':	$p->types[$i] = 4; break;
					case 'file':	$p->types[$i] = 5; break;
					case 'task':	$p->types[$i] = 6; break;
					case 'event':	$p->types[$i] = 7; break;
					default: $p->types[$i] = intval($p->types[$i]);
				}
			// $ids = Util\toNumericArray($p->types);
			if(!empty($p->types)) $fq[] = 'type:('.implode(' OR ', $p->types).')';
		}

		if(!empty($p->templates)){
			$ids = Util\toNumericArray($p->templates);
			if(!empty($ids)) $fq[] = 'template_id:('.implode(' OR ', $ids).')';
		}
		if(!empty($p->template_types)){
			if(!is_array($p->template_types)) $p->template_types = explode(',', $p->template_types);
			if(!empty($p->template_types)) $fq[] = 'template_type:("'.implode('" OR "', $p->template_types).'")';
		}
		
		if( isset($p->folders) && !empty($GLOBALS['folder_templates']) ){
			if( $p->folders ) $fq[] = 'template_type:("'.implode('" AND "', $GLOBALS['folder_templates']).'")';
			else $fq[] = '!template_id:('.implode(' OR ', $GLOBALS['folder_templates']).')';
		}

		if(!empty($p->tags)){
			$ids = Util\toNumericArray($p->tags);
			if(!empty($ids))$fq[] = 'sys_tags:('.implode(' OR ', $ids).')';
		}
		if(!empty($p->dateStart)) $fq[] = 'date:['.$p->dateStart.' TO '.$p->dateEnd.']';

		$this->params['fq'] = $fq;
		/* end of adding additional query filters */

		/* setting highlight if query parrameter is present /**/
		if(!empty($this->query)){
			$this->params['hl'] = 'true';
			$this->params['hl.fl'] = 'name,content';
			$this->params['hl.simple.pre'] = '<em class="hl">';
			$this->params['hl.simple.post'] = '</em>';
			$this->params['hl.usePhraseHighlighter'] = 'true';
			$this->params['hl.highlightMultiTerm'] = 'true';
			$this->params['hl.fragsize'] = '256';
		}

		$this->prepareFacetsParams();
		$this->setFilters();
	}
	
	private function setFilters(){
		$p = &$this->inputParams;
		if(!empty($p->filters)){
			$p->filters = $p->filters;
			foreach($p->filters as $k => $v){
				if($k == 'OR'){
					$conditions = array();
					foreach($v as $sk => $sv){ 
						$condition = $this->analizeFilter($sk, $sv, false);
						if(!empty($condition)) $conditions[] = $condition;
					}
					if(!empty($conditions)) $this->params['fq'][] = '('.implode(' OR ', $conditions).')';
				}else{
					$condition = $this->analizeFilter($k, $v);
					if(!empty($condition)) $this->params['fq'][] = $condition;
				}
				
			}
		}
	}
	private function analizeFilter(&$k, &$v, $withtag = true){
		$rez = null;
		if($k == 'due'){
			$k = 'date_end';
			foreach($v as $sv){
				for($i = 0; $i < sizeof($sv->values); $i++ )
					switch(substr($sv->values[$i], 1)){
						case 'next7days': $sv->values[$i] = '[NOW/DAY TO NOW/DAY+6DAY]'; break;
						case 'overdue': 
							$k = 'status';
							$sv->values[$i] = '1'; break;
						case 'today': $sv->values[$i] = '[NOW/DAY TO NOW/DAY]'; break;
						case 'next31days': $sv->values[$i] = '[NOW/DAY TO NOW/DAY+31DAY]'; break;
						case 'thisWeek': $sv->values[$i] = '['.$this->current_week_diapazon().']'; break;
						case 'thisMonth': $sv->values[$i] = '[NOW/MONTH TO NOW/MONTH+1MONTH]'; break;
					}
			}
		}elseif( ($k == 'date') || ($k == 'cdate') ){
			foreach($v as $sv){
				for($i = 0; $i < sizeof($sv->values); $i++ )
					switch(substr($sv->values[$i], 1)){
						case 'today': $sv->values[$i] = '[NOW/DAY TO NOW/DAY+1DAY]'; break;
						case 'yesterday': $sv->values[$i] = '[NOW/DAY-1DAY TO NOW/DAY]'; break;
						case 'thisWeek': $sv->values[$i] = '['.$this->current_week_diapazon().']'; break;
						case 'thisMonth': $sv->values[$i] = '[NOW/MONTH TO NOW/MONTH+1MONTH]'; break;
					}
			}
		}elseif($k == 'assigned') $k = 'user_ids';
		elseif(substr($k, 0, 4) == 'stg_'){
			$k = 'sys_tags';
		}
		elseif(substr($k, 0, 4) == 'ttg_'){
			$k = 'tree_tags';
		}

		if(!is_array($v)) $v = array($v);
		foreach($v as $sv){
			if(!empty($sv->values)){
				if($k == 'user_ids')
					for ($i=0; $i < sizeof($sv->values); $i++) {
						if($sv->values[$i] == -1){
							$this->params['fq'][] = $this->getFacetTag('user_ids').'!user_ids:[* TO *]';//{!tag=unassigned}
							array_splice($sv->values, $i, 1);
						}
					}
				if(!empty($sv->values)) $rez = ($withtag ? $this->getFacetTag($k): '' ).$k.':('.implode(' '.$sv->mode.' ', $sv->values).')';//'{!tag='.$k.'}'
			}
		}
		return $rez;
	}
	private function getFacetTag($k){
		if(empty($this->params['facet.field'])) return '';
		foreach($this->params['facet.field'] as $f){
			if(substr($f, -strlen($k) -1) == '}'.$k){
				if(preg_match('/ex=([^\s}]+)/', $f, $matches))
					return '{!tag='.$matches[1].'}';

			}
		}
		return '';
	}

	private function prepareFacetsParams(){
		$p = &$this->inputParams;
		switch(@$p->facets){
			case 'general':
				$this->params['facet.field'] = array(
					'{!ex=template_type key=0template_type}template_type'
					,'{!ex=cid key=1cid}cid'
					,'{!ex=sys_tags key=2sys_tags}sys_tags'
					,'{!ex=tree_tags key=3tree_tags}tree_tags'
					,'{!ex=template_id key=4template_id}template_id'
				);
				//Created: Today / Yesterday / This week / This month
				$this->params['facet.query'] = array(
					'{!key=0today}cdate:[NOW/DAY TO NOW/DAY+1DAY ]'
					,'{!key=1yesterday}cdate:[NOW/DAY-1DAY TO NOW/DAY]'
					,'{!key=2thisWeek}cdate:['.$this->current_week_diapazon().']'
					,'{!key=3thisMonth}cdate:[NOW/MONTH TO NOW/MONTH+1MONTH]'
				);/**/
				break;
			case 'actions':
				$this->params['facet.field'] = array(
					'{!ex=subtype key=0subtype}subtype'
					,'{!ex=cid key=1cid}cid'
					,'{!ex=sys_tags key=2sys_tags}sys_tags'
					,'{!ex=tree_tags key=3tree_tags}tree_tags'
					,'{!ex=template_id key=4template_id}template_id'
				);
				$this->params['facet.query'] = array(
					//Date: Today / Yesterday / This week / This month
					'{!key=date_0today}date:[NOW/DAY TO NOW/DAY+1DAY ]'
					,'{!key=date_1yesterday}date:[NOW/DAY-1DAY TO NOW/DAY]'
					,'{!key=date_2thisWeek}date:['.$this->current_week_diapazon().']'
					,'{!key=date_3thisMonth}date:[NOW/MONTH TO NOW/MONTH+1MONTH]'
					//Created: Today / Yesterday / This week / This month
					,'{!key=cdate_0today}cdate:[NOW/DAY TO NOW/DAY+1DAY ]'
					,'{!key=cdate_1yesterday}cdate:[NOW/DAY-1DAY TO NOW/DAY]'
					,'{!key=cdate_2thisWeek}cdate:['.$this->current_week_diapazon().']'
					,'{!key=cdate_3thisMonth}cdate:[NOW/MONTH TO NOW/MONTH+1MONTH]'
				);/**/
				break;
			case 'actiontasks':
				$this->params['facet.field'] = array(
					'{!ex=status key=0status}status'
					,'{!ex=user_ids key=1assigned}user_ids'
					,'{!ex=cid key=2cid}cid'
				);
				break;
			case 'calendar':
				$this->params['facet.query'] = array(
					//Due date: Next 7 days / Overdue / Today / Next 31 days / This week / This month
					'{!key=0next7days}date_end:[NOW/DAY TO NOW/DAY+6DAY ]'
					,'{!key=1overdue}status:1'
					,'{!key=2today}date_end:[NOW/DAY TO NOW/DAY]'
					,'{!key=3next31days}date_end:[NOW/DAY TO NOW/DAY+31DAY ]'
					,'{!key=4thisWeek}date_end:['.$this->current_week_diapazon().']'
					,'{!key=5thisMonth}date_end:[NOW/MONTH TO NOW/MONTH+1MONTH]'
					,'{!ex=unassigned key=unassigned}!user_ids:[* TO *]'
				);/**/
				$this->params['facet.field'] = array(
					/*Status: Overdue / Active / Completed / Pending
					there were following task statuses: Pending, Active, Closed 
						with following substatuses/flags: Active:  Completed + Missed , Closed: Completed + Missed 
					Now we'll transfer to statuses: 
						1 Overdue - all tasks that passes their deadline will be moved to this status (from pending or active)
						2 Active
						3 Completed - it's equivalent to a completed and closed task (all tasks will be with autoclose = true, so that when all responsible users mark task as completed - it'll be automatically closed)
						4 Pending /**/
					'{!ex=type key=0type}type'
					,'{!ex=status key=1status}status'
					,'{!ex=category_id key=2category_id}category_id'
					,'{!ex=importance key=3importance}importance'
					//Assigned: Me / Unassigned / Ben Batros / Amrit Singh / Indira Goris 
					,'{!ex=user_ids key=4assigned}user_ids'
					//Created: Me / Ben Batros / Rupert Skillbeck
					,'{!ex=cid key=5cid}cid'
				);
				break;	
			case 'tasks':
				$this->params['facet.query'] = array(
					//Due date: Next 7 days / Overdue / Today / Next 31 days / This week / This month
					'{!key=0next7days}date_end:[NOW/DAY TO NOW/DAY+6DAY ]'
					,'{!key=1overdue}status:1'
					,'{!key=2today}date_end:[NOW/DAY TO NOW/DAY]'
					,'{!key=3next31days}date_end:[NOW/DAY TO NOW/DAY+31DAY ]'
					,'{!key=4thisWeek}date_end:['.$this->current_week_diapazon().']'
					,'{!key=5thisMonth}date_end:[NOW/MONTH TO NOW/MONTH+1MONTH]'
					,'{!ex=unassigned key=unassigned}!user_ids:[* TO *]'
				);/**/
				$this->params['facet.field'] = array(
					/*Status: Overdue / Active / Completed / Pending
					there were following task statuses: Pending, Active, Closed 
						with following substatuses/flags: Active:  Completed + Missed , Closed: Completed + Missed 
					Now we'll transfer to statuses: 
						1 Overdue - all tasks that passes their deadline will be moved to this status (from pending or active)
						2 Active
						3 Completed - it's equivalent to a completed and closed task (all tasks will be with autoclose = true, so that when all responsible users mark task as completed - it'll be automatically closed)
						4 Pending /**/
					'{!ex=status key=1status}status'
					,'{!ex=category_id key=2category_id}category_id'
					,'{!ex=importance key=3importance}importance'
					//Assigned: Me / Unassigned / Ben Batros / Amrit Singh / Indira Goris 
					,'{!ex=user_ids key=4assigned}user_ids'
					//Created: Me / Ben Batros / Rupert Skillbeck
					,'{!ex=cid key=5cid}cid'
				);

				break;
			case 'activeTasksPerUsers':
				if(!empty($p->facetPivot)){
					$this->rows = 0;
					$this->params['facet.pivot'] = $p->facetPivot;
				}
				break;
			case 'first_letter':
				$this->params['facet.field'] = array(
					'{!ex=name_first_letter key=0fl}name_first_letter'
				);
				$this->params['facet.method'] = "enum";
				$this->params['facet.sort'] = "lex";
				break;
			default: 
				if(!empty($p->{'facet.field'})) $this->params['facet.field'] = $p->{'facet.field'};
				if(!empty($p->{'facet.query'})) $this->params['facet.query'] = $p->{'facet.query'};
				if(!empty($p->{'facet.pivot'})) $this->params['facet.pivot'] = $p->{'facet.pivot'};
				if(!empty($p->{'facet.method'})) $this->params['facet.method'] = $p->{'facet.method'};
				if(!empty($p->{'facet.sort'})) $this->params['facet.sort'] = $p->{'facet.sort'};
				if(!empty($p->{'facet.missing'})) $this->params['facet.missing'] = $p->{'facet.missing'};
				break;
		}
		if(!empty($this->params['facet.field']) || !empty($this->params['facet.pivot']) ){
			$this->params['facet'] = 'true';
			$this->params['facet.mincount'] = isset($p->{'facet.mincount'}) ? $p->{'facet.mincount'} : 1;
		}
	}

	private function executeQuery(){
		try{
			$this->results = $this->solr->search($this->query, $this->start, $this->rows, $this->params);
		}catch( \Exception $e ){
			throw new \Exception("An error occured: \n\n {$e->__toString()}");
		}
	}

	private function processResult(){
		$rez = array( 'total' => $this->results->response->numFound, 'data' => array() );
		if(is_debug_host()) $rez['search'] = array('query' => $this->query, 'start' => $this->start, 'rows' => $this->rows, 'params' => $this->params);
		$sr = &$this->results;
		foreach($sr->response->docs as $d){
			$rd = array();
			foreach($d as $fn => $fv) $rd[$fn] = is_array($fv) ? implode(',', $fv) : $fv;
			if(!empty($sr->highlighting)){
				if(!empty($sr->highlighting->{$rd['id']}->{'name'})) $rd['hl'] = $sr->highlighting->{$rd['id']}->{'name'}[0];
				if(!empty($sr->highlighting->{$rd['id']}->{'content'})) $rd['content'] = $sr->highlighting->{$rd['id']}->{'content'}[0];
			}
			$res = DB\mysqli_query_params('select f_get_tree_path($1)', array($rd['id'])) or die(DB\mysqli_query_error());
			if($r = $res->fetch_row()) $rd['path'] = $r[0];
			$res->close();
			$rez['data'][] = $rd;
		}
		$rez['facets'] = $this->processResultFacets();
		$this->results = $rez;
	}

	private function processResultFacets(){
    		$rez = array();
		$sr = &$this->results;
		if(empty($sr->facet_counts)) return false;
		$fc = &$sr->facet_counts;
		switch(@$this->inputParams->facets){
			case 'general':
				foreach($fc->facet_fields as $k => $v){
					$k = substr($k, 1);
					switch($k){
						case 'sys_tags': 
							$this->analizeSystemTagsFacet($v, $rez);
							break;
						case 'tree_tags': 
							$this->analizeTreeTagsFacet($v, $rez);
							break;
						default: 
							$rez[$k] = array('f' => $k, 'items' => $v);
							break;
					}
				}
				foreach($fc->facet_queries as $k => $v)
					if($v > 0) $rez['cdate']['items'][$k] = $v;
				
				break;
			case 'actiontasks':
				$sql = 'select count(*) from tree where pid = $1 and `type` = 6';//active and overdue
				$res = DB\mysqli_query_params($sql, $this->inputParams->pid) or die(DB\mysqli_query_error());
				if($r = $res->fetch_row()) $rez['total'] = $r[0];
				$res->close();
				$sql = 'select count(*) from tree t join tasks tt where t.pid = $1 and t.`type` = 6 and t.id = tt.id and tt.status < 3';//active and overdue
				$res = DB\mysqli_query_params($sql, $this->inputParams->pid) or die(DB\mysqli_query_error());
				if($r = $res->fetch_row()) $rez['active'] = $r[0];
				$res->close();
				break;
			case 'actions':
				foreach($fc->facet_fields as $k => $v){
					$k = substr($k, 1);
					switch($k){
						case 'sys_tags': 
							$this->analizeSystemTagsFacet($v, $rez);
							break;
						case 'tree_tags': 
							$this->analizeTreeTagsFacet($v, $rez);
							break;
						default: 
							$rez[$k] = array('f' => $k, 'items' => $v);
							break;
					}
				}
				foreach($fc->facet_queries as $k => $v){
					$k = explode('_', $k);
					if($v > 0) $rez[$k[0]]['items'][$k[1]] = $v;
				}
				break;
			case 'calendar':
			case 'tasks':
				foreach($fc->facet_queries as $k => $v){
					if($k == 'unassigned') continue;
					if($v > 0) $rez['due']['items'][$k] = $v;
				}
				foreach($fc->facet_fields as $k => $v){
					$k = substr($k, 1);
					$rez[$k] = array('f' => $k, 'items' => $v);
					if($k == 'assigned' && !empty($fc->facet_queries) && !empty($fc->facet_queries->{'unassigned'}) ) $rez[$k]['items']->{'-1'} = $fc->facet_queries->{'unassigned'};
				}
				break;
			case 'activeTasksPerUsers':
				if(!empty($fc->facet_pivot))
				foreach($fc->facet_pivot->{$this->inputParams->facetPivot} as $f){
					$row = array('id' => $f->value, 'total' => $f->count );
					if(!empty($f->pivot)){
						foreach($f->pivot as $st){
							if($st->value == 1) $row['total2'] = $st->count;
						}
					}
					$rez[] = $row;
				}
				break;
			case 'first_letter':
				foreach($fc->facet_fields as $k => $v){
					$k = substr($k, 1);
					$rez[$k] = array('f' => $k, 'items' => $v);
				}
				break;
			default: 
				$rez = $fc;
				break;
		}

		return $rez;
	}

	public function analizeSystemTagsFacet($values, &$rez){
		$groups = defined('CB\\config\\tags_facet_grouping') ? config\tags_facet_grouping : 'pids';
		$ids = array();
		foreach($values as $k => $v) $ids[] = $k;
		if(empty($ids)) return false;
		switch($groups){
			case 'all': 
				$rez['sys_tags'] = array('f' => 'sys_tags', 'items' => $values);
				// return false;
				break;
			case 'pids': 
				$res = DB\mysqli_query_params('select t.id, t.pid, p.l'.USER_LANGUAGE_INDEX.' `title` from tags t join tags p on t.pid = p.id where t.id in ('.implode(',', $ids).')') or die(DB\mysqli_query_error());
				while($r = $res->fetch_assoc()){
					$rez['stg_'.$r['pid']]['f'] = 'sys_tags';
					$rez['stg_'.$r['pid']]['title'] = $r['title'];
					$rez['stg_'.$r['pid']]['items'][$r['id']] = $values->{$r['id']};
				}
				$res->close();
				break;
			default: 
				$res = DB\mysqli_query_params('select t.id, t.pid, p.l'.USER_LANGUAGE_INDEX.' `title` from tags t join tags p on t.pid = p.id where t.id in ('.implode(',', $ids).') and p.id in('.$groups.')') or die(DB\mysqli_query_error());
				while($r = $res->fetch_assoc()){
					$rez['stg_'.$r['pid']]['f'] = 'sys_tags';
					$rez['stg_'.$r['pid']]['title'] = $r['title'];
					$rez['stg_'.$r['pid']]['items'][$r['id']] = $values->{$r['id']};
					unset($values->{$r['id']});
				}
				$res->close();
				if(!empty($values))
					foreach($values as $k => $v){
						$rez['sys_tags']['items'][$k] = $v;
					}
				break;
		}
		return true;
	}

	public function analizeTreeTagsFacet($values, &$rez){
		$groups = defined('CB\\config\\tags_facet_grouping') ? config\tags_facet_grouping : 'pids';
		$ids = array();
		foreach($values as $k => $v) $ids[] = $k;
		// die($groups);
		if(empty($ids)) return false;
		
		$names = array();
		/* selecting names*/
		$res = DB\mysqli_query_params('select t.id, t.name from tree t where t.id in ('.implode(',', $ids).')') or die(DB\mysqli_query_error());
		while($r = $res->fetch_assoc()) $names[$r['id']] = $r['name'];
		$res->close();
		/* end of selecting names*/

		switch($groups){
			case 'all': 
				foreach($values as $k => $v) $rez['tree_tags']['items'][$k] = array('name' => $names[$k], 'count' => $v);
				break;
			case 'pids': 
				$res = DB\mysqli_query_params('select t.id, t.pid, p.name `title` from tree t join tree p on t.pid = p.id where t.id in ('.implode(',', $ids).')') or die(DB\mysqli_query_error());
				while($r = $res->fetch_assoc()){
					$rez['ttg_'.$r['pid']]['f'] = 'tree_tags';
					$rez['ttg_'.$r['pid']]['title'] = $r['title'];
					$rez['ttg_'.$r['pid']]['items'][$r['id']] =  array('name' => $names[$r['id']], 'count' => $values->{$r['id']});
				}
				$res->close();
				break;
			default: 
				$res = DB\mysqli_query_params('select t.id, t.pid, p.name `title` from tree t join tree p on t.pid = p.id where t.id in ('.implode(',', $ids).') and p.id in('.$groups.')') or die(DB\mysqli_query_error());
				while($r = $res->fetch_assoc()){
					$rez['ttg_'.$r['pid']]['f'] = 'tree_tags';
					$rez['ttg_'.$r['pid']]['title'] = $r['title'];
					$rez['ttg_'.$r['pid']]['items'][$r['id']] = array('name' => $names[$r['id']], 'count' => $values->{$r['id']});
					unset($values->{$r['id']});
				}
				$res->close();
				
				if(!empty($values))
					foreach($values as $k => $v) 
						if(isset( $names[$k] )) $rez['tree_tags']['items'][$k] = array('name' => $names[$k], 'count' => $v);
				break;
		}
		return true;
	}

}