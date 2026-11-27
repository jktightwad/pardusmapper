<?php
declare(strict_types=1);

namespace Pardusmapper;

use Pardusmapper\Core\ApiResponse;
use Pardusmapper\Core\MySqlDB;
use Pardusmapper\Core\Settings;

class DB
{
    /**
     * Get cluster by sector
     *
     * @param integer|null $id
     * @param string|null $code
     * @param string|null $sector
     * @return object|null
     */
    public static function cluster(?int $id = null, ?string $code = null, ?string $sector = null): object|null
    {
        if (is_null($id) && is_null($code) && is_null($sector)) {
            return null;
        }

        $db = MySqlDB::instance();

        if($sector) {
            $db->execute('SELECT * FROM Pardus_Clusters WHERE c_id = (SELECT c_id FROM Pardus_Sectors WHERE name = ?)', [
                's', $sector
            ]);
        } else if($code) {
            $db->execute('SELECT * FROM Pardus_Clusters WHERE code = ?', [
                's', $code
            ]);
        } else {
            $db->execute('SELECT * FROM Pardus_Clusters WHERE c_id = ?', [
                'i', $id
            ]);
        }

        return $db->numRows() === 1 ? $db->fetchObject() : null;
    }

    /**
     * Get sector by sector id or name
     *
     * @param integer|null $id
     * @param string|null $sector
     * @return object|null
     */
    public static function sector(?int $id = null, ?string $sector = null): object|null
    {
        if (is_null($id) && is_null($sector)) {
            return null;
        }

        $db = MySqlDB::instance();

        if($sector) {
            $db->execute('SELECT * FROM Pardus_Sectors WHERE name = ?', [
                's', $sector
            ]);
        } else {
            // TODO: check the code but this should probably be
            // SELECT * FROM Pardus_Sectors WHERE s_id = ?
            // so mysql will not have to scan that many rows

            $db->execute('SELECT * FROM Pardus_Sectors WHERE s_id <= ? ORDER BY s_id DESC LIMIT 1', [
                'i', $id
            ]);
        }

        return $db->numRows() === 1 ? $db->fetchObject() : null;
    }

    /**
     * Get map by location id
     *
     * @param string $universe
     * @param integer|null $id
     * @return object|null
     */
    public static function map(string $universe, ?int $id): object|null
    {
        if (is_null($id)) {
            return null;
        }

        $db = MySqlDB::instance();

        $db->execute(sprintf('SELECT *, UTC_TIMESTAMP() AS today FROM %s_Maps WHERE id = ?', $universe), [
            'i', $id
        ]);

        if ($db->numRows() != 1) {
            return null;
        }

        return $db->numRows() === 1 ? $db->fetchObject() : null;
    }

    /**
     * Save map info from pardus
     *
     * @param string $universe
     * @param string $image
     * @param integer $id
     * @param integer $sb
     * @return boolean
     */
    public static function map_add(string $universe, string $image, int $id, int $sb = 0): bool
    {
        $db = MySqlDB::instance();

        // if (preg_match('/^\d+$/', $image)) {
        //     debug(__FILE__, $image);
        //     $this->execute('SELECT image FROM background WHERE id = ?', [
        //         'i', $image
        //     ]);
        //     $dbImg = $this->fetchObject();
        //     $image = $dbImg->image;
        // }

        $db->execute(sprintf('INSERT INTO %s_Maps (`id`, `bg`, `security`) VALUES (?, ?, 0)', $universe), [
            'is', $id, $image
        ]);

        $params = [];

        if ($sb) {
            debug(__METHOD__, __LINE__, 'we have building');

            $b = self::building(universe: $universe, id: $sb);
            $x = Coordinates::getX($id,$b->starbase,13);
            $y = Coordinates::getY($id,$b->starbase,13,$x);

            $params['cluster'] = $b->cluster;
            $params['sector'] = $b->sector;
            $params['x'] = $x;
            $params['y'] = $y;            
        } else {
            debug(__METHOD__, __LINE__, 'no building');

            $s = self::sector(id: $id);  // Here, $id is passed as expected
            $c = self::cluster(id: $s->c_id);  // Assuming this is correct
            $x = Coordinates::getX($id,$s->s_id,$s->rows);
            $y = Coordinates::getY($id,$s->s_id,$s->rows,$x);

            $params['cluster'] = $c->name;
            $params['sector'] = $s->name;
            $params['x'] = $x;
            $params['y'] = $y;            
        }

        $bindType = [];
        $bindValues = [];
        $fields = [];

        foreach($params as $key => $value) {
            $fields[] = sprintf('`%s` = ?', $key);
            $bindValues[] = $value;
            if (is_int($value)) {
                $bindType[] = 'i';
            } elseif (is_float($value)) {
                $bindType[] = 'd';
            } else {
                $bindType[] = 's';
            }
        }

        $bindValues[] = $id;
        $bindType[] = 'i';

        $binds = [];
        $binds[] = implode('', $bindType);
        $binds = array_merge($binds, $bindValues);
        $query = sprintf('UPDATE %s_Maps SET %s WHERE id = ?', $universe, implode(', ', $fields));

        $db = MySqlDB::instance();
        $db->execute($query, $binds);

        return true;
    }

    /**
     * Update map FG image
     *
     * @param string $universe
     * @param string $image
     * @param integer $id
     * @return boolean
     */
    public static function map_update_fg(string $universe, string $image, int $id): bool
    {
        $query = sprintf('UPDATE %s_Maps SET `fg` = ? , `fg_updated` = UTC_TIMESTAMP() WHERE id = ?', $universe);
        $params = ['si', $image, $id];

        $db = MySqlDB::instance();
        $db->execute($query, $params);
    
        return true;
    }

    /**
     * Update map BG image
     *
     * @param string $universe
     * @param string $image
     * @param integer $id
     * @return boolean
     */
    public static function map_update_bg(string $universe, string $image, ?int $id): bool
    {
        $query = sprintf('UPDATE %s_Maps SET `bg` = ? WHERE id = ?', $universe);
        $params = ['si', $image, $id];

        $db = MySqlDB::instance();
        $db->execute($query, $params);
        
        return true;
    }

    /**
     * Update NPC on map
     *
     * @param string $universe
     * @param string $image
     * @param integer $id
     * @param integer $cloaked
     * @param integer|null $nid
     * @return boolean
     */
    public static function map_update_npc(string $universe, string $image, int $id, int $cloaked, ?int $nid = null): bool
    {
        debug(__METHOD__, func_get_args());

        $npc_cloacked = $cloaked ? 1 : null;

        $db = MySqlDB::instance();

        // Update map tile
        // REVIEW
        // the initial code was only updating the image if uncloaked
        // not sure i understand why yet
        // TODO maybe add an if/else here if needed
        $db->execute(sprintf('UPDATE %s_Maps SET `npc` = ?, `npc_cloaked` = ?, `npc_updated` = UTC_TIMESTAMP() WHERE id = ?', $universe), [
            'sii', $image, $npc_cloacked, $id
        ]);

        $db->execute(sprintf('UPDATE %s_Test_Npcs SET `cloaked` = ?, `updated` = UTC_TIMESTAMP() WHERE (deleted IS NULL OR deleted = 0) AND id = ?', $universe), [
            'ii', $npc_cloacked, $id
        ]);
        
        if (!is_null($nid)) {
            $db->execute(sprintf('UPDATE %s_Test_Npcs SET `nid` = ?, `updated` = UTC_TIMESTAMP() WHERE (deleted IS NULL OR deleted = 0) AND id = ?', $universe), [
                'ii', $nid, $id
            ]);
        }

        return true;
    }

    /**
     * Get building by location id
     *
     * @param string $universe
     * @param integer|null $id
     * @param string|null $name
     * @return object|null
     */
    public static function building(string $universe, ?int $id = null, ?string $name = null,): object|null
    {
        if (empty($id) && empty($name)) {
            return null;
        }

        $query = sprintf('SELECT *, UTC_TIMESTAMP() AS today FROM %s_Buildings WHERE 1 = 1', $universe);
        $conditions = [];
        $bindType = [];
        $bindValues = [];

        if (!empty($name)) {
            $conditions[] = 'name = ?';
            $bindType[] = 's';
            $bindValues[] = $name;
        }

        if (!empty($id)) {
            $conditions[] = 'id = ?';
            $bindType[] = 'i';
            $bindValues[] = $id;
        }

        $query .= ' AND ' . implode(' AND ', $conditions);

        $params = [];
        $params[] = implode('', $bindType);
        $params = array_merge($params, $bindValues);

        $db = MySqlDB::instance();
        $db->execute($query, $params);

        return $db->numRows() === 1 ? $db->fetchObject() : null;
    }

    /**
     * Update building fields
     *
     * @param string $universe
     * @param integer $id
     * @param array $params
     * @return boolean
     */
    public static function building_update(string $universe, int $id, array $params): bool
    {
        debug(__METHOD__, func_get_args());

        if (0 === count($params)) {
            return false;
        }

        debug('Updating building fields', $id, $params);

        $bindType = [];
        $bindValues = [];
        $fields = [];
        foreach($params as $key => $value) {
            $fields[] = sprintf('`%s` = ?', $key);
            $bindValues[] = $value;
            if (is_int($value)) {
                $bindType[] = 'i';
            } elseif (is_float($value)) {
                $bindType[] = 'd';
            } else {
                $bindType[] = 's';
            }
        }

        $bindValues[] = $id;
        $bindType[] = 'i';

        $binds = [];
        $binds[] = implode('', $bindType);
        $binds = array_merge($binds, $bindValues);

        $db = MySqlDB::instance();

        $db->execute(sprintf('UPDATE %s_Buildings SET updated = UTC_TIMESTAMP(), %s WHERE id = ?', $universe, implode(', ', $fields)), $binds);

        return true;
    }

    /**
     * Add new building
     *
     * @param string $universe
     * @param string $image
     * @param integer $id
     * @param integer $sb
     * @return boolean
     */
    public static function building_add(string $universe, string $image, int $id, int $sb): bool
    {
        debug(__METHOD__, func_get_args());

        $db = MySqlDB::instance();

        $b = self::building(universe: $universe, id: $id);
        if (!$b) {
            //create building
            $db->execute(sprintf('INSERT INTO %s_Buildings (`id`, `security`) VALUES (?, 0)', $universe), [
                'i', $id
            ]);

            // load building after create
            $b = self::building(universe: $universe, id: $id);
        } else {
            self::building_stock_remove(universe: $universe, id: $id);
        }

        // marked spotted date
        $db->execute(sprintf('UPDATE %s_Buildings SET `spotted` = UTC_TIMESTAMP() WHERE id = ?', $universe), [
            'i', $id
        ]);

        $updateBuilding = [];
        if ($sb) {
            $x = Coordinates::getX($id, $b->starbase, 13);
            $y = Coordinates::getY($id, $b->starbase, 13, $x);
            $updateBuilding['cluster'] = $b->cluster;
            $updateBuilding['sector'] = $b->sector;

        } else {
            // Get Sector Info and Cluster Info
            $s = DB::sector(id: $id);  // Here, $id is passed as expected
            $c = DB::cluster(id: $s->c_id);  // Assuming this is correct

            // Calculate X and Y of NPC
            $x = Coordinates::getX($id, $s->s_id, $s->rows);
            $y = Coordinates::getY($id, $s->s_id, $s->rows, $x);

            $updateBuilding['cluster'] = $c->name;
            $updateBuilding['sector'] = $s->name;

            self::building_stock_add(universe: $universe, image: $image, id: $id);
        }

        $updateBuilding['x'] = $x;
        $updateBuilding['y'] = $y;
        $updateBuilding['image'] = $image;

        // update location and image fields
        self::building_update(universe: $universe, id: $id, params: $updateBuilding);

        // update map
        $db->execute(sprintf('UPDATE %s_Maps SET `fg` = ?, `fg_spotted` = UTC_TIMESTAMP(), `fg_updated` = UTC_TIMESTAMP() WHERE id = ?', $universe), [
            'si', $image, $id
        ]);

        return true;
    }

    /**
     * Delete building from DB
     *
     * @param string $universe
     * @param integer $id
     * @param integer $sb
     * @return void
     */
    public static function building_remove(string $universe, int $id, int $sb)
    {
        debug(__METHOD__, func_get_args());

        $db = MySqlDB::instance();

        // all these queries only need the id so, initialize the params once and use it for all of them
        $params = ['i', $id];


        // empty map tile content
        $query = sprintf('UPDATE %s_Maps SET `fg` = NULL , `fg_spotted` = UTC_TIMESTAMP(), `fg_updated` = UTC_TIMESTAMP() WHERE id = ?', $universe);
        $db->execute($query, $params);

        // remove the building
        $query = sprintf('DELETE FROM %s_Buildings WHERE id = ?', $universe);
        $db->execute($query, $params);

        // remove the stocks
        $query = sprintf('DELETE FROM %s_New_Stock WHERE id = ?', $universe);
        $db->execute($query, $params);

        // if it's a SB, remove the missions, squads and equiment as well
        if ($sb) {
            $query = sprintf('DELETE FROM %s_Test_Missions WHERE source_id = ?', $universe);
            $db->execute($query, $params);

            $query = sprintf('DELETE FROM %s_Squadrons WHERE id = ?', $universe);
            $db->execute($query, $params);

            // REVIEW
            // this was commented in the original code
            // i don't know if previously save equiment didn't work or not but, now it works so,
            // remove equipment as well
            // TODO: double check this again
            $query = sprintf('DELETE FROM %s_Equipment WHERE id = ?', $universe);
            $db->execute($query, $params);
        }

        return true;
    }

    /**
     * Update building stock fields
     *
     * @param string $universe
     * @param integer $id
     * @param array $params
     * @return boolean
     */
    public static function building_stock_update(string $universe, int $id, array $params): bool
    {
        debug('Updating building stock fields', $id, $params);

        $bindType = [];
        $bindValues = [];
        $fields = [];
        $date = null;

        if (in_array('date', array_keys($params))) {
            $date = $params['date'];
            unset($params['date']);
            $bindType[] = 's';
            $bindValues[] = $date;
        }

        foreach($params as $key => $value) {
            $fields[] = sprintf('`%s` = ?', $key);
            $bindValues[] = $value;
            if (is_int($value)) {
                $bindType[] = 'i';
            } elseif (is_float($value)) {
                $bindType[] = 'd';
            } else {
                $bindType[] = 's';
            }
        }

        $bindValues[] = $id;
        $bindType[] = 'i';

        $binds = [];
        $binds[] = implode('', $bindType);
        $binds = array_merge($binds, $bindValues);

        $query = sprintf(
            'UPDATE %s_Buildings SET stock_updated = %s %s WHERE id = ?'
            , $universe
            , (is_null($date) ? 'UTC_TIMESTAMP()' : 'STR_TO_DATE(?, \'%a %b %e %T GMT %Y\')')
            , (count($fields) === 0 ? '' : ', ' . implode(', ', $fields))
        );

        $db = MySqlDB::instance();
        $db->execute($query, $binds);

        return true;
    }

    /**
     * Update building equipment fields
     *
     * @param string $universe
     * @param integer $id
     * @param array $params
     * @return boolean
     */
    public static function building_equipment_update(string $universe, int $id, array $params): bool
    {
        if (0 === count($params)) {
            return false;
        }

        debug('Updating building equipment fields', func_get_args());

        $bindType = [];
        $bindValues = [];
        $fields = [];
        $date = null;

        if (in_array('date', array_keys($params))) {
            $date = $params['date'];
            unset($params['date']);
            $bindType[] = 's';
            $bindValues[] = $date;
        }

        foreach($params as $key => $value) {
            $fields[] = sprintf('`%s` = ?', $key);
            $bindValues[] = $value;
            if (is_int($value)) {
                $bindType[] = 'i';
            } elseif (is_float($value)) {
                $bindType[] = 'd';
            } else {
                $bindType[] = 's';
            }
        }

        $bindValues[] = $id;
        $bindType[] = 'i';

        $binds = [];
        $binds[] = implode('', $bindType);
        $binds = array_merge($binds, $bindValues);

        $query = sprintf(
            'UPDATE %s_Buildings SET eq_updated = %s %s WHERE id = ?'
            , $universe
            , (is_null($date) ? 'UTC_TIMESTAMP()' : 'STR_TO_DATE(?, \'%a %b %e %T GMT %Y\')')
            , (count($fields) === 0 ? '' : ', ' . implode(', ', $fields))
        );

        $db = MySqlDB::instance();
        $db->execute($query, $binds);

        return true;
    }

    /**
     * Get static building data
     *
     * @param string|null $name
     * @param string|null $image
     * @return object|array|null
     */
    public static function building_static(?string $name = null, ?string $image = null, bool $limit = true): object|array|null
    {
        $db = MySqlDB::instance();

        if (is_null($name) && is_null($image)) {
            return null;
        }
        
        $query = 'SELECT * FROM Pardus_Buildings_Data WHERE 1 = 1';
        $conditions = [];
        $bindType = [];
        $bindValues = [];

        if (!empty($name)) {
            $conditions[] = 'name = ?';
            $bindType[] = 's';
            $bindValues[] = $name;
        }

        if (!empty($image)) {
            $conditions[] = 'image = ?';
            $bindType[] = 's';
            $bindValues[] = $image;
        }

        $query .= ' AND ' . implode(' AND ', $conditions);

        if ($limit) {
            $query .= ' ORDER BY image LIMIT 1';
        }

        $params = [];
        $params[] = implode('', $bindType);
        $params = array_merge($params, $bindValues);

        $db->execute($query, $params);

        if ($db->numRows() === 1) {
            return $db->fetchObject();
        }

        $return = [];
        while($q = $db->nextObject()) { $return[] = $q; }

        return $return;
    }

    /**
     * Get static upkeep data
     *
     * @param string|null $fg
     * @param string|null $res
     * @param int|null $upkeep
     * @return object|array|null
     */
    public static function upkeep_static(?string $fg = null, ?string $res = null, ?int $upkeep = null): object|array|null
    {
        debug(__METHOD__, func_get_args());
        $db = MySqlDB::instance();

        if (is_null($fg) && is_null($res) && is_null($upkeep)) {
            return null;
        }
        
        $query = 'SELECT * FROM Pardus_Upkeep_Data WHERE 1 = 1';
        $conditions = [];
        $bindType = [];
        $bindValues = [];

        if (!empty($fg)) {
            $conditions[] = 'name = (SELECT name FROM Pardus_Buildings_Data WHERE image = ?)';
            $bindType[] = 's';
            $bindValues[] = $fg;
        }

        if (!empty($res)) {
            $conditions[] = 'res = ?';
            $bindType[] = 's';
            $bindValues[] = $res;
        }

        if (!is_null($upkeep)) {
            $conditions[] = 'upkeep = ?';
            $bindType[] = 'i';
            $bindValues[] = $upkeep;
        }

        $query .= ' AND ' . implode(' AND ', $conditions);

        $params = [];
        $params[] = implode('', $bindType);
        $params = array_merge($params, $bindValues);

        $db->execute($query, $params);

        if ($db->numRows() === 1) {
            return $db->fetchObject();
        }

        $return = [];
        while($q = $db->nextObject()) { $return[] = $q; }

        return $return;
    }

    /**
     * Get NPC by name
     *
     * @param string|null $name
     * @param string|null $image
     * @return object|null
     */
    public static function npc_static(?string $name = null, ?string $image = null): object|null
    {
        $db = MySqlDB::instance();

        if ($name) {
            $db->execute('SELECT * FROM Pardus_Npcs WHERE name = ?', [
                's', $name
            ]);
        } else {
            $db->execute('SELECT * FROM Pardus_Npcs WHERE image = ?', [
                's', $image
            ]);
        }

        return $db->numRows() === 1 ? $db->fetchObject() : null;
    }

    /**
     * Get NPC by location
     *
     * @param string $universe
     * @param int|null $id
     * @param bool|null $excludeDeleted
     * @return object|null
     */
    public static function npc(string $universe, int $id, ?bool $excludeDeleted = true): object|null
    {
        $db = MySqlDB::instance();

        if ($excludeDeleted) {
            $db->execute(sprintf('SELECT *, UTC_TIMESTAMP() AS today FROM %s_Test_Npcs WHERE (deleted is null or deleted = 0) and id = ?', $universe), [
                'i', $id
            ]);
        } else {
            $db->execute(sprintf('SELECT *, UTC_TIMESTAMP() AS today FROM %s_Test_Npcs WHERE id = ?', $universe), [
                'i', $id
            ]);
        }

        return $db->numRows() === 1 ? $db->fetchObject() : null;
    }

    /**
     * Add NPC
     *
     * @param string $universe
     * @param string $image
     * @param integer|null $id
     * @param string|null $sector
     * @param integer $x
     * @param integer $y
     * @param integer|null $nid
     * @return boolean
     */
    public static function npc_add(string $universe, string $image, ?int $id, ?string $sector, int $x, int $y, ?int $nid = null)
    {
        debug(__METHOD__, func_get_args());

        if ($id) {
            $s = DB::sector(id: $id);  // Here, $id is passed as expected
            $c = DB::cluster(id: $s->c_id);  // Assuming this is correct
            $x = Coordinates::getX($id, $s->s_id, $s->rows);
            $y = Coordinates::getY($id, $s->s_id, $s->rows, $x);
        } else {
            // Pass null as the first argument when you are using the sector name
            $s = DB::sector(sector: $sector);  // $id is null here, so we fetch by sector
            $c = DB::cluster(id: $s->c_id);  // Assuming this is correct
            $id = Coordinates::getID($s->s_id, $s->rows, $x, $y);
        }

        $npc = self::npc_static(image: $image);
        $n = self::npc(universe: $universe, id: $id, excludeDeleted: true);
        debug(__METHOD__, 'Loaded NPC', $n);

        $db = MySqlDB::instance();

        if (!$n) {
            debug(__METHOD__, 'NPC not found, adding');

            $query = sprintf('INSERT INTO %s_Test_Npcs (`id`) VALUES (?)', $universe);
            $params = [
                'i', $id
            ];
            $db->execute($query, $params);

            // REVIEW this doesn't make sense
            // we udpate NID for a newly added NPC so, deleted is already null
            // skip a second query and just update NID at the end with the updated date
            // if ($nid) {
            //     $this->query('UPDATE ' . $uni . '_Test_Npcs SET `nid` = \'' . $nid . '\' WHERE (deleted is null or deleted = 0) and id = ' . $id);
            // }

            // Combine multiple updates into one query
            $query = sprintf("UPDATE %s_Test_Npcs
                        SET
                            `cluster` = ?, `sector` = ?, `cloaked` = null, `x` = ?, `y` = ?, 
                            `name` = ?, `image` = ?, 
                            `hull` = ?, `armor` = ?, `shield` = ?, `spotted` = UTC_TIMESTAMP()
                    WHERE (deleted is null or deleted = 0) and id = ?
            ", $universe);
            $params = [
                'ssiissiiii',
                $c->name, $s->name, $x, $y,
                $npc->name, $image, 
                $npc->hull, $npc->armor, $npc->shield, $id
            ];

            $db->execute($query, $params);

            // Update map tile
            $query = sprintf('UPDATE %s_Maps SET `npc` = ? , `npc_cloaked` = null, `npc_spotted` = UTC_TIMESTAMP() WHERE id = ?', $universe);
            $params = [
                'si', $image, $id
            ];

            $db->execute($query, $params);

        } else {
            debug(__METHOD__, 'NPC exists', $n->image, $image);
            
            if ($n->image != $image) {
                debug(__METHOD__, 'it is a different NPC, remove and add');
    
                self::npc_remove(universe: $universe, id: $id, deleteMissions: true);
                self::npc_add(universe: $universe, image: $image, id: $id, sector: $sector, x: $x, y: $y, nid: $nid); // Adding $nid
                return true;
            }
        }

        if(!is_null($nid)) {
            $db->execute(sprintf('UPDATE %s_Test_Npcs SET `nid` = ?, `updated` = UTC_TIMESTAMP() WHERE (deleted is null or deleted = 0) and id = ?', $universe), [
                'ii', $nid, $id
            ]);
        } else {
            $db->execute(sprintf('UPDATE %s_Test_Npcs SET `updated` = UTC_TIMESTAMP() WHERE (deleted is null or deleted = 0) and id = ?', $universe), [
                'i', $id
            ]);
        }

        // Update map tile
        $db->execute(sprintf('UPDATE %s_Maps SET `npc_updated` = UTC_TIMESTAMP() WHERE id = ?', $universe), [
            'i', $id
        ]);

        return true;
    }

    /**
     * Background and foreground image of every mapped tile in part of a sector (uses the Sector index).
     *
     * @return array list of objects: x, y, bg, fg
     */
    public static function map_tiles(string $universe, string $sector, int $x0, int $x1, int $y0, int $y1): array
    {
        if (!in_array($universe, Settings::UNIVERSE, true)) {
            return [];
        }

        $db = MySqlDB::instance();
        $db->execute(sprintf('SELECT x, y, bg, fg FROM %s_Maps WHERE sector = ? AND x BETWEEN ? AND ? AND y BETWEEN ? AND ?', $universe), [
            'siiii', $sector, $x0, $x1, $y0, $y1
        ]);

        $rows = [];
        while ($row = $db->fetchObject()) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Grid size of several sectors in one query.
     *
     * @param array $names sector names
     * @return array sector name => object(name, rows, cols, c_id)
     */
    public static function sector_dims(array $names): array
    {
        $names = array_values(array_unique(array_filter($names, 'is_string')));
        if (count($names) === 0) {
            return [];
        }

        $db = MySqlDB::instance();
        $db->execute(
            sprintf('SELECT name, rows, cols, c_id FROM Pardus_Sectors WHERE name IN (%s)', implode(',', array_fill(0, count($names), '?'))),
            array_merge([str_repeat('s', count($names))], $names)
        );

        $dims = [];
        while ($row = $db->fetchObject()) {
            $dims[$row->name] = $row;
        }

        return $dims;
    }

    /**
     * Raw NPC records for the given images across universes, oldest first.
     *
     * {Universe}_Test_Npcs gets one row each time an NPC is first seen on a tile (`spotted`).
     * `updated` is NOT a "last seen" time: npc_remove overwrites it with the moment someone
     * saw the tile empty. So callers should date sightings by `spotted`; `last_seen` is only
     * meaningful for rows still active (NPC not yet reported gone).
     * See NPC::mark_sightings() for turning records into de-duplicated sightings.
     *
     * Uses the image_nid (image, nid) index on {Universe}_Test_Npcs, so it only touches the
     * tracked rows (a few thousand) rather than the millions of other NPC records.
     *
     * @param array $images NPC images (see NPC::HISTORY)
     * @param string|null $universe limit to one universe
     * @param int|null $nid limit to one NPC id (requires $universe)
     * @param string|null $sector limit to one sector
     * @return array list of objects: uni, nid, sector, x, y, image, spotted, last_seen, active
     */
    public static function npc_sightings(array $images, ?string $universe = null, ?int $nid = null, ?string $sector = null): array
    {
        if (count($images) === 0) {
            return [];
        }

        $universes = Settings::UNIVERSE;
        if (!is_null($universe)) {
            if (!in_array($universe, $universes, true)) {
                return [];
            }
            $universes = [$universe];
        }

        $placeholders = implode(',', array_fill(0, count($images), '?'));
        $parts = [];
        $params = [''];
        foreach ($universes as $u) {
            $sql = sprintf(
                "SELECT '%s' AS uni, nid, sector, x, y, image, spotted,
                        COALESCE(updated, spotted) AS last_seen,
                        CASE WHEN deleted IS NULL OR deleted = 0 THEN 1 ELSE 0 END AS active
                FROM %s_Test_Npcs
                WHERE image IN (%s) AND sector IS NOT NULL AND x IS NOT NULL AND y IS NOT NULL AND spotted IS NOT NULL",
                $u, $u, $placeholders
            );
            $params[0] .= str_repeat('s', count($images));
            array_push($params, ...array_values($images));

            if (!is_null($sector)) {
                $sql .= ' AND sector = ?';
                $params[0] .= 's';
                $params[] = $sector;
            }

            if (!is_null($nid)) {
                $sql .= ' AND nid = ?';
                $params[0] .= 'i';
                $params[] = $nid;
            }
            $parts[] = $sql;
        }

        $db = MySqlDB::instance();
        $db->execute(implode(' UNION ALL ', $parts) . ' ORDER BY spotted', $params);

        $rows = [];
        while ($row = $db->fetchObject()) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Remove NPC
     *
     * @param string $universe
     * @param integer $id
     * @param bool $deleteMissions
     * @return boolean
     */
    public static function npc_remove(string $universe, int $id, bool $deleteMissions = false): bool
    {
        debug(__METHOD__, func_get_args());

        $db = MySqlDB::instance();

        // Combine the updates into one query
        $db->execute(sprintf('UPDATE %s_Maps SET `npc` = null , `npc_cloaked` = null, `npc_updated` = UTC_TIMESTAMP() WHERE id = ?', $universe), [
            'i', $id
        ]);
        $db->execute(sprintf('UPDATE %s_Test_Npcs SET `deleted` = 1, `cloaked` = null, `updated` = UTC_TIMESTAMP() WHERE id = ?', $universe), [
            'i', $id
        ]);
        
        // REVIEW
        // i think we need to remove any assasination missions for this NPC when the NPC is killed
        // also, i'm not sure how cloaked works
        // while testing, a NPC cloaked and in DB was marked as deleted
        // i added a deleteMissions parameter passed as true only from importnpcinfo.php 
        // when kill a NPC only then remove the mission/s
        $npc = self::npc(universe: $universe, id: $id);
        if ($npc && $deleteMissions) {
            debug(__METHOD__, 'NPC Killed or replaced by building, remove missions as well');
            self::mission_remove(universe: $universe, sector: $npc->sector, x: $npc->x, y: $npc->y);
        }

        return true;
    }

    /**
     * Update NPC health
     *
     * @param string $universe
     * @param integer $id
     * @param integer $hull
     * @param integer $armor
     * @param integer $shield
     * @param integer|null $nid
     * @return boolean
     */
    public static function npc_update_health(string $universe, int $id, int $hull, int $armor, int $shield, ?int $nid = null): bool
    {
        debug(__METHOD__, func_get_args());

        $db = MySqlDB::instance();

        $query = sprintf("UPDATE %s_Test_Npcs
                    SET `nid` = ?,
                        `hull` = ?, `armor` = ?, `shield` = ?, `updated` = UTC_TIMESTAMP()
                WHERE id = ?
        ", $universe);
        $params = [
            'iiiii', $nid, $hull, $armor, $shield, $id
        ];

        $db->execute($query, $params);
        $db->execute(sprintf('UPDATE %s_Maps SET `npc_updated` = UTC_TIMESTAMP() WHERE id = ?', $universe), [
            'i', $id
        ]);
        return true;
    }

    /**
     * Get user
     *
     * @param string $universe
     * @param int|null $id
     * @param string|null $username
     * @return object|null
     */
    public static function user(string $universe, ?int $id = null, ?string $username = null): object|null
    {
        if (is_null($id) && is_null($username)) {
            return null;
        }

        $db = MySqlDB::instance();

        if ($username) {
            $db->execute(sprintf('SELECT *, UTC_TIMESTAMP() AS today FROM %s_Users WHERE LOWER(username) = ?', $universe), [
                's', $username
            ]);
        } else {
            $db->execute(sprintf('SELECT *, UTC_TIMESTAMP() AS today FROM %s_Users WHERE id = ?', $universe), [
                'i', $id
            ]);
        }


        return $db->numRows() === 1 ? $db->fetchObject() : null;
    }

    /**
     * Get stocks
     *
     * @param string $universe
     * @param integer|null $id
     * @param string|null $name
     * @param bool $warStatus
     * @param bool $nonZero
     * @return array
     */
    public static function stocks(string $universe, ?int $id, ?string $name = null, bool $warStatus = false, bool $nonZero = false): array
    {
        if (is_null($id)) {
            return null;
        }

        $bindType = [];
        $bindValues = [];

        $query = sprintf('SELECT *, UTC_TIMESTAMP() AS today FROM %s_New_Stock WHERE id = ?', $universe);
        $bindType[] = 'i';
        $bindValues[] = $id;

        if ($name) {
            $query .= ' AND name = ?';
            $bindType[] = 's';
            $bindValues[] = $name;
        }

        if ($nonZero) {
            $query .= ' AND (amount > 0 OR max > 0)';
        }

        if ($warStatus) {
            $query .= ' AND (SELECT WarStatus FROM War_Status where Universe = ?) = 0';
            $bindType[] = 's';
            $bindValues[] = $universe;
        }

        $params = [];
        $params[] = implode('', $bindType);
        $params = array_merge($params, $bindValues);

        $db = MySqlDB::instance();
        $db->execute($query, $params);

        $stocks = [];
        while($q = $db->nextObject()) { $stocks[] = $q; }

        return $stocks;
    }

    /**
     * Create stocks row
     *
     * @param string $universe
     * @param integer $id
     * @param string $name
     * @return boolean
     */
    public static function stock_create(string $universe, int $id, string $name): bool
    {
        debug('Create stock', func_get_args());

        $query = sprintf('INSERT INTO %s_New_Stock (id, name) VALUES (?, ?)', $universe);
        $params = ['is', $id, $name];

        $db = MySqlDB::instance();
        $db->execute($query, $params);

        return true;
    }

    /**
     * Update stock fields
     *
     * @param string $universe
     * @param integer $id
     * @param string $name
     * @param array $params
     * @return boolean
     */
    public static function stock_update(string $universe, int $id, string $name, array $params): bool
    {
        debug('Updating stock fields', func_get_args());

        $bindType = [];
        $bindValues = [];
        $fields = [];
        foreach($params as $key => $value) {
            $fields[] = sprintf('`%s` = ?', $key);
            $bindValues[] = $value;
            if (is_int($value)) {
                $bindType[] = 'i';
            } elseif (is_float($value)) {
                $bindType[] = 'd';
            } else {
                $bindType[] = 's';
            }
        }

        $bindValues[] = $id;
        $bindType[] = 'i';
        $bindValues[] = $name;
        $bindType[] = 's';

        $binds = [];
        $binds[] = implode('', $bindType);
        $binds = array_merge($binds, $bindValues);
        $query = sprintf('UPDATE %s_New_Stock SET %s WHERE id = ? AND name = ?', $universe, implode(', ', $fields));

        $db = MySqlDB::instance();
        $db->execute($query, $binds);

        return true;
    }

    /**
     * Get stock by location id
     *
     * @param string $universe
     * @param integer|null $id
     * @param string|null $name
     * @return object|array|null
     */
    public static function building_stock(string $universe, ?int $id, ?string $name = null): object|array|null
    {
        if (is_null($id)) {
            return null;
        }

        $stocks = self::stocks(universe: $universe, id: $id, name: $name);

        return match (count($stocks)) {
            0 => null,
            1 => array_shift($stocks),
            default => $stocks,
        };
    }

    /**
     * Add building empty stock
     *
     * @param string $universe
     * @param string $image
     * @param integer $id
     * @return boolean
     */
    public static function building_stock_add(string $universe, string $image, int $id): bool
    {
        debug(__METHOD__, func_get_args());

        $query = 'SELECT res,upkeep FROM Pardus_Buildings_Data b, Pardus_Upkeep_Data u WHERE b.name = u.name AND b.image = ?';
        $params = ['s', $image];

        $db = MySqlDB::instance();
        $db->execute($query, $params);

        $res = [];
        while ($r = $db->nextObject()) {
            $res[] = $r;
        }

        if (0 === count($res)) {
            return false;
        }

        foreach ($res as $r) {
            self::stock_create(universe: $universe, id: $id, name: $r->res);
        }

        self::building_stock_update(universe: $universe, id: $id, params: []);
        
        return true;
    }

    /**
     * Remove building stock
     *
     * @param string $universe
     * @param integer $id
     * @return boolean
     */
    public static function building_stock_remove(string $universe, int $id): bool
    {
        debug(__METHOD__, func_get_args());

        $query = sprintf('DELETE FROM %s_New_Stock WHERE id = ?', $universe);
        $params = ['i', $id];

        $db = MySqlDB::instance();
        $db->execute($query, $params);
        
        return true;
    }

    /**
     * Get static locations
     *
     * @return array
     */
    public static function static_locations(): array
    {
        $db = MySqlDB::instance();

        // Perform the SELECT query
        $db->execute("SELECT * FROM Pardus_Static_Locations");

        // Check if the query was successful
        http_response($db->numRows() < 1, ApiResponse::OK, 'Missing static locations');

        // Initialize an array to hold the results
        $static = [];

        // Fetch each row as an object
        while ($c = $db->fetchObject()) {
            $static[] = $c->id;
        }

        return $static;
    }

    /**
     * Get resource data
     *
     * @param string $image
     * @return object|null
     */
    public static function res_data(string $image): object|null
    {
        $db = MySqlDB::instance();

        $db->execute('SELECT * FROM Pardus_Res_Data WHERE image = ?', [
            's', $image
        ]);

        return $db->numRows() === 1 ? $db->fetchObject() : null;
    }

    /**
     * Returns startc pardus res data
     *
     * @return array
     */
    public static function res_data_static(): array
    {
        $db = MySqlDB::instance();

        $res_img = [];
        $res_id = [];

        $db->execute('SELECT * FROM Pardus_Res_Data');
        while ($q = $db->nextObject()) {
            $res_img[$q->name] = $q->image;
            $res_id[$q->name] = $q->r_id;
        }

        return [$res_img, $res_id];
    }

    /**
     * Returns startc sectors list
     * 
     * @return array
     */
    public static function sectors_static(): array
    {
        $db = MySqlDB::instance();

        $sector = [];
        $db->execute('SELECT * from Pardus_Sectors order by name');
        while ($s = $db->nextObject()) { $sector[] = $s; }

        return $sector;
    }

    /**
     * Update crew fields
     *
     * @param string $universe
     * @param string $name
     * @param array $params
     * @return boolean
     */
    public static function crew_update(string $universe, string $name, array $params): bool
    {
        debug('Updating crew fields', func_get_args());

        $bindType = [];
        $bindValues = [];
        $fields = [];
        foreach($params as $key => $value) {
            $fields[] = sprintf('`%s` = ?', $key);
            $bindValues[] = $value;
            if (is_int($value)) {
                $bindType[] = 'i';
            } elseif (is_float($value)) {
                $bindType[] = 'd';
            } else {
                $bindType[] = 's';
            }
        }

        $bindValues[] = $name;
        $bindType[] = 's';

        $binds = [];
        $binds[] = implode('', $bindType);
        $binds = array_merge($binds, $bindValues);

        $db = MySqlDB::instance();
        $db->execute(sprintf('UPDATE %s_Crew SET updated = UTC_TIMESTAMP(), %s WHERE name = ?', $universe, implode(', ', $fields)), $binds);

        return true;
    }

    /**
     * Create crew row
     *
     * @param string $universe
     * @param string $name
     * @param integer $location
     * @return boolean
     */
    public static function crew_create(string $universe, string $name, int $location): bool
    {
        debug('Create crew', func_get_args());

        $query = sprintf('INSERT INTO %s_Crew (name,loc) VALUES (?, ?)', $universe);
        $params = ['si', $name, $location];

        $db = MySqlDB::instance();
        $db->execute($query, $params);

        return true;
    }

    /**
     * Remove crew
     *
     * @param string $universe
     * @param integer $location
     * @return boolean
     */
    public static function crew_delete(string $universe, int $location): bool
    {
        debug(__METHOD__, func_get_args());

        $query = sprintf('DELETE FROM %s_Crew WHERE loc = ?', $universe);
        $params = ['i', $location];

        $db = MySqlDB::instance();
        $db->execute($query, $params);
        
        return true;
    }

    /**
     * Get equipment
     *
     * @param string $universe
     * @param string $name
     * @param integer $location
     * @return object|null
     */
    public static function equipment(string $universe, string $name, int $location): object|null
    {
        if (empty($name)) {
            return null;
        }

        $db = MySqlDB::instance();

        $query = sprintf('SELECT *, UTC_TIMESTAMP() AS today FROM %s_Equipment WHERE loc = ? AND name = ?', $universe);
        $db->execute($query, [
            'is', $location, $name
        ]);

        return $db->numRows() === 1 ? $db->fetchObject() : null;
    }

    /**
     * Create equipment row
     *
     * @param string $universe
     * @param string $name
     * @param integer $location
     * @return boolean
     */
    public static function equipment_create(string $universe, string $name, int $location): bool
    {
        debug('Create equpment', func_get_args());

        $query = sprintf('INSERT INTO %s_Equipment (name,loc) VALUES (?, ?)', $universe);
        $params = ['si', $name, $location];

        $db = MySqlDB::instance();
        $db->execute($query, $params);

        return true;
    }

    /**
     * Update equipment fields
     *
     * @param string $universe
     * @param string $name
     * @param integer $location
     * @param array $params
     * @return boolean
     */
    public static function equipment_update(string $universe, string $name, int $location, array $params): bool
    {
        debug('Updating equipment fields', func_get_args());

        $bindType = [];
        $bindValues = [];
        $fields = [];
        foreach($params as $key => $value) {
            $fields[] = sprintf('`%s` = ?', $key);
            $bindValues[] = $value;
            if (is_int($value)) {
                $bindType[] = 'i';
            } elseif (is_float($value)) {
                $bindType[] = 'd';
            } else {
                $bindType[] = 's';
            }
        }

        $bindValues[] = $location;
        $bindType[] = 'i';
        $bindValues[] = $name;
        $bindType[] = 's';

        $binds = [];
        $binds[] = implode('', $bindType);
        $binds = array_merge($binds, $bindValues);
        $query = sprintf('UPDATE %s_Equipment SET %s WHERE loc = ? AND name = ?', $universe, implode(', ', $fields));

        $db = MySqlDB::instance();
        $db->execute($query, $binds);

        return true;
    }

    /**
     * Remove mission
     *
     * @param string $universe
     * @param integer|bool $id
     * @param string|null $sector
     * @param integer|null $x
     * @param integer|null $y
     * @return boolean
     */
    public static function mission_remove(string $universe, ?int $id = null, ?string $sector = null, ?int $x = null, ?int $y = null): bool
    {
        debug(__METHOD__, func_get_args());

        // REVIEW
        // the else is never reached
        // i checked the places where remove missions is used and only ID is passed
        // i'm getting rid of the source_id part
        // if (is_null($id)) {
        //     return false;
        // }
        // if ($id) {
        //     $this->query('DELETE FROM ' . $uni . '_Test_Missions WHERE id = ' . $id);
        // } else {
        //     $this->query('DELETE FROM ' . $uni . '_Test_Missions WHERE source_id = ' . $id);
        // }


        $db = MySqlDB::instance();

        if (!is_null($id)) {
            $db->execute(sprintf('DELETE FROM %s_Test_Missions WHERE id = ?', $universe), [
                'i', $id
            ]);
        } elseif (!is_null($sector) && !is_null($x) && !is_null($y)) {
            $query = sprintf('DELETE FROM %s_Test_Missions WHERE t_sector = ? AND t_x = ? AND t_y = ?', $universe);
            $params = [
                'sii', $sector, $x, $y
            ];

            $db->execute($query, $params);
        }

        return true;
    }

    /**
     * Remove WH
     *
     * @param string $universe
     * @param integer $id
     * @return boolean
     */
    public static function wh_remove(string $universe, int $id)
    {
        debug(__METHOD__, func_get_args());

        $db = MySqlDB::instance();

        $db->execute(sprintf('UPDATE %s_Maps SET `fg` = NULL , `wormhole` = NULL WHERE id = ?', $universe), [
            'i', $id
        ]);

        return true;
    }

    /**
     * Update Pardus WH status
     *
     * @param string $universe
     * @return boolean
     */
    public static function wh_update_pardus_status(string $universe)
    {
        debug(__METHOD__, func_get_args());

        //December 3, 2015 05:26:01 GMT
        $START_TIME = 1449120361000;

        //determine closed WH
        $deltaT = round(microtime(true) * 1000) - $START_TIME;
        $days = $deltaT / 1000 / 60 / 60 / 24; //milliseconds to days
        
        //Pegasus is 3 days out of sync with Orion/Artemis
        if ($universe == 'Pegasus') {
            $days = $days + 3;
        }

        //closed WH switches every two days, new cycle every four WHs
        $shift =  floor($days / 2) % 4;
        //var nextDay = 2 - Math.floor(days) % 2; //days until closed WH changes
        $WHs = [162194, 160536, 139222, 163055];
        $PWHs = [159205, 159794, 151630, 156058];
        $closedWH = $WHs[$shift]; //163055;//
        $closedPWH = $PWHs[$shift]; //156058; //

        $db = MySqlDB::instance();

        //var nextWH = WHs[(shift+1) % 4];
        $db->execute(sprintf('UPDATE %s_Maps SET `fg` = \'foregrounds/wormholeseal_open.png\' , `fg_updated` = UTC_TIMESTAMP() WHERE id != ? and id != ? and id in (162194,160536,139222,163055,159205,159794,151630,156058)', $universe), [
            'ii', $closedWH, $closedPWH
        ]);
        $db->execute(sprintf('UPDATE %s_Maps SET `fg` = \'foregrounds/wormholeseal_closed.png\' , `fg_updated` = UTC_TIMESTAMP() WHERE (id = ? or id = ?) and id in (162194,160536,139222,163055,159205,159794,151630,156058)', $universe), [
            'ii', $closedWH, $closedPWH
        ]);

        return true;
    }
}