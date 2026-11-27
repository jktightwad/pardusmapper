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
     * Latest live NPC record on a tile. A tile can hold several NPCs (Vyrex and Pirates share
     * tiles and stack), so this prefers an uncloaked record and then the most recently seen.
     * With $excludeDeleted false, the latest record of any state.
     *
     * @param string $universe
     * @param int $id
     * @param bool|null $excludeDeleted
     * @return object|null
     */
    public static function npc(string $universe, int $id, ?bool $excludeDeleted = true): object|null
    {
        $db = MySqlDB::instance();

        $live = $excludeDeleted ? '(deleted IS NULL OR deleted = 0) AND ' : '';
        $db->execute(sprintf('SELECT *, UTC_TIMESTAMP() AS today FROM %s_Test_Npcs WHERE %sid = ? ORDER BY (cloaked IS NULL OR cloaked = 0) DESC, COALESCE(updated, spotted) DESC, kid DESC LIMIT 1', $universe, $live), [
            'i', $id
        ]);

        return $db->fetchObject() ?: null;
    }

    /**
     * Every live NPC record on a tile, oldest first.
     *
     * @param string $universe
     * @param int $id
     * @return array
     */
    public static function npc_live(string $universe, int $id): array
    {
        $db = MySqlDB::instance();

        $db->execute(sprintf('SELECT *, UTC_TIMESTAMP() AS today FROM %s_Test_Npcs WHERE (deleted IS NULL OR deleted = 0) AND id = ? ORDER BY kid', $universe), [
            'i', $id
        ]);

        $rows = [];
        while ($row = $db->fetchObject()) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Pick the live record an NPC sighting belongs to.
     *
     * With an nID (the pilot is on the tile): the record of that image with that nID, else one of
     * that image that has no nID yet. A record with a different nID is a different NPC.
     * Without an nID (seen from another tile): any record of that image.
     *
     * @param array $live rows from npc_live()
     * @param string $image
     * @param int|null $nid
     * @return object|null
     */
    public static function npc_match(array $live, string $image, ?int $nid): object|null
    {
        $same = array_values(array_filter($live, static fn ($r) => (string) $r->image === $image));

        if (is_null($nid)) {
            return $same[0] ?? null;
        }
        foreach ($same as $r) {
            if (!is_null($r->nid) && (int) $r->nid === $nid) {
                return $r;
            }
        }
        foreach ($same as $r) {
            if (is_null($r->nid)) {
                return $r;
            }
        }

        return null;
    }

    /**
     * Record that an NPC was seen on a tile; returns the kid of its record.
     *
     * - A matching live record (see npc_match) is marked seen: uncloaked, and its nID filled in
     *   if it had none. An existing nID is never changed to a different one.
     * - Otherwise a new record is added. Only Vyrex and Pirates share a tile with other NPCs
     *   (NPC::shares_tile), so a new non-sharing NPC ends the records of the other non-sharing
     *   NPCs on the tile. Vyrex/Pirate records are kept: one may be cloaked or hidden under a
     *   bigger NPC on the nav screen.
     *
     * @param string $universe
     * @param string $image
     * @param integer|null $id tile id, or null to use sector + x/y
     * @param string|null $sector
     * @param integer $x
     * @param integer $y
     * @param integer|null $nid only from the pilot's own tile (Other Ships list or combat screen)
     * @return integer|null kid, or null if the tile is unknown
     */
    public static function npc_add(string $universe, string $image, ?int $id, ?string $sector, int $x, int $y, ?int $nid = null): ?int
    {
        debug(__METHOD__, func_get_args());

        if ($id) {
            $s = DB::sector(id: $id);
        } else {
            $s = DB::sector(sector: $sector);
        }
        if (is_null($s)) {
            return null;
        }
        $c = DB::cluster(id: $s->c_id);
        if ($id) {
            $x = Coordinates::getX($id, $s->s_id, $s->rows);
            $y = Coordinates::getY($id, $s->s_id, $s->rows, $x);
        } else {
            $id = Coordinates::getID($s->s_id, $s->rows, $x, $y);
        }

        $live = self::npc_live(universe: $universe, id: $id);
        $match = self::npc_match($live, $image, $nid);
        debug(__METHOD__, 'Matched NPC', $match);

        $db = MySqlDB::instance();

        if ($match) {
            $db->execute(sprintf('UPDATE %s_Test_Npcs SET `cloaked` = NULL, `nid` = COALESCE(`nid`, ?), `updated` = UTC_TIMESTAMP() WHERE kid = ?', $universe), [
                'ii', $nid, $match->kid
            ]);
            $kid = (int) $match->kid;

            $db->execute(sprintf('UPDATE %s_Maps SET `npc` = ?, `npc_cloaked` = NULL, `npc_updated` = UTC_TIMESTAMP() WHERE id = ?', $universe), [
                'si', $image, $id
            ]);

            return $kid;
        }

        if (!NPC::shares_tile($image)) {
            $end = [];
            foreach ($live as $r) {
                if (!NPC::shares_tile((string) $r->image)) {
                    $end[] = (int) $r->kid;
                }
            }
            if ($end) {
                debug(__METHOD__, 'NPCs that cannot share a tile with it are gone', $end);
                self::npc_end(universe: $universe, kids: $end, sync: false);
            }
        }

        $npc = self::npc_static(image: $image);
        $db->execute(sprintf('INSERT INTO %s_Test_Npcs (`id`, `nid`, `cluster`, `sector`, `cloaked`, `x`, `y`, `name`, `image`, `hull`, `armor`, `shield`, `spotted`, `updated`)
                VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())', $universe), [
            'iissiissiii',
            $id, $nid, $c->name ?? null, $s->name, $x, $y,
            $npc->name ?? null, $image,
            (int) ($npc->hull ?? 0), (int) ($npc->armor ?? 0), (int) ($npc->shield ?? 0)
        ]);

        $db->execute(sprintf('SELECT kid FROM %s_Test_Npcs WHERE id = ? AND image = ? AND (deleted IS NULL OR deleted = 0) ORDER BY kid DESC LIMIT 1', $universe), [
            'is', $id, $image
        ]);
        $new = $db->fetchObject();

        $db->execute(sprintf('UPDATE %s_Maps SET `npc` = ?, `npc_cloaked` = NULL, `npc_spotted` = UTC_TIMESTAMP(), `npc_updated` = UTC_TIMESTAMP() WHERE id = ?', $universe), [
            'si', $image, $id
        ]);

        return $new ? (int) $new->kid : null;
    }

    /**
     * End NPC records (the NPC is gone or was killed).
     *
     * @param string $universe
     * @param array $kids record ids
     * @param bool $deleteMissions also remove missions targeting those tiles (kills only)
     * @param bool $sync update the map tiles to show what is still there
     * @return void
     */
    public static function npc_end(string $universe, array $kids, bool $deleteMissions = false, bool $sync = true): void
    {
        debug(__METHOD__, func_get_args());

        $kids = array_values(array_unique(array_map('intval', $kids)));
        if (!$kids) {
            return;
        }

        $db = MySqlDB::instance();
        $in = implode(',', array_fill(0, count($kids), '?'));
        $types = str_repeat('i', count($kids));

        $db->execute(sprintf('SELECT DISTINCT id, sector, x, y FROM %s_Test_Npcs WHERE kid IN (%s)', $universe, $in), array_merge([$types], $kids));
        $tiles = [];
        while ($t = $db->fetchObject()) {
            $tiles[] = $t;
        }

        $db->execute(sprintf('UPDATE %s_Test_Npcs SET `deleted` = 1, `cloaked` = NULL, `updated` = UTC_TIMESTAMP() WHERE kid IN (%s) AND (deleted IS NULL OR deleted = 0)', $universe, $in), array_merge([$types], $kids));

        foreach ($tiles as $t) {
            if ($deleteMissions && !is_null($t->sector)) {
                self::mission_remove(universe: $universe, sector: $t->sector, x: (int) $t->x, y: (int) $t->y);
            }
            if ($sync) {
                self::npc_sync_tile(universe: $universe, id: (int) $t->id);
            }
        }
    }

    /**
     * Mark an NPC record cloaked (its tile looks empty, but it may still be there).
     * `updated` is set only when it goes cloaked, so it says how long it has been unseen.
     *
     * @param string $universe
     * @param int $kid
     * @return void
     */
    public static function npc_cloak(string $universe, int $kid): void
    {
        $db = MySqlDB::instance();
        $db->execute(sprintf('UPDATE %s_Test_Npcs SET `cloaked` = 1, `updated` = UTC_TIMESTAMP() WHERE kid = ? AND cloaked IS NULL', $universe), [
            'i', $kid
        ]);
    }

    /**
     * End the live records of a one-per-cluster NPC everywhere in the cluster except one tile.
     *
     * @param string $universe
     * @param string $cluster
     * @param string $image
     * @param int $exceptId
     * @return void
     */
    public static function npc_end_elsewhere(string $universe, string $cluster, string $image, int $exceptId): void
    {
        $db = MySqlDB::instance();
        $db->execute(sprintf('SELECT kid FROM %s_Test_Npcs WHERE (deleted IS NULL OR deleted = 0) AND cluster = ? AND image = ? AND id <> ?', $universe), [
            'ssi', $cluster, $image, $exceptId
        ]);
        $kids = [];
        while ($r = $db->fetchObject()) {
            $kids[] = (int) $r->kid;
        }
        self::npc_end(universe: $universe, kids: $kids);
    }

    /**
     * Make the map tile show what is still recorded on it: the latest live NPC (uncloaked first),
     * or nothing.
     *
     * @param string $universe
     * @param int $id
     * @return void
     */
    public static function npc_sync_tile(string $universe, int $id): void
    {
        $n = self::npc(universe: $universe, id: $id);
        $db = MySqlDB::instance();

        if ($n) {
            $db->execute(sprintf('UPDATE %s_Maps SET `npc` = ?, `npc_cloaked` = ?, `npc_updated` = UTC_TIMESTAMP() WHERE id = ?', $universe), [
                'sii', $n->image, empty($n->cloaked) ? null : 1, $id
            ]);
        } else {
            $db->execute(sprintf('UPDATE %s_Maps SET `npc` = NULL, `npc_cloaked` = NULL, `npc_updated` = UTC_TIMESTAMP() WHERE id = ?', $universe), [
                'i', $id
            ]);
        }
    }

    /**
     * Sector-to-sector wormhole links recorded on the Orion map (the universes share their layout).
     * Scanning the map table is not cheap, so the result is cached in the system temp folder for a day.
     *
     * @return array list of [sector, target sector]
     */
    public static function wormhole_links(): array
    {
        $cache = sys_get_temp_dir() . '/pardusmapper_wormhole_links.json';
        if (is_file($cache) && (time() - (int) filemtime($cache)) < 86400) {
            $links = json_decode((string) file_get_contents($cache), true);
            if (is_array($links)) {
                return $links;
            }
        }

        $db = MySqlDB::instance();
        $db->execute("SELECT DISTINCT sector, wormhole FROM Orion_Maps WHERE wormhole IS NOT NULL AND wormhole <> '' AND sector IS NOT NULL");

        $links = [];
        while ($row = $db->fetchObject()) {
            $links[] = [(string) $row->sector, (string) $row->wormhole];
        }
        @file_put_contents($cache, json_encode($links));

        return $links;
    }

    /**
     * Wormhole tiles (wormholes, X and Y holes) in the given sectors, from the Orion map (the
     * universes share their layout).
     *
     * @param array $sectors sector names
     * @return array sector => list of [x, y]
     */
    public static function wormhole_tiles(array $sectors): array
    {
        $sectors = array_values(array_unique(array_filter($sectors, 'is_string')));
        if (!$sectors) {
            return [];
        }

        $db = MySqlDB::instance();
        $in = implode(',', array_fill(0, count($sectors), '?'));
        $db->execute(sprintf("SELECT sector, x, y FROM Orion_Maps WHERE sector IN (%s)
                AND ((wormhole IS NOT NULL AND wormhole <> '') OR fg LIKE '%%wormhole%%' OR fg LIKE '%%xhole%%' OR fg LIKE '%%yhole%%')", $in),
            array_merge([str_repeat('s', count($sectors))], $sectors));

        $tiles = [];
        while ($row = $db->fetchObject()) {
            $tiles[(string) $row->sector][] = [(int) $row->x, (int) $row->y];
        }

        return $tiles;
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
     * Remove every NPC on a tile: a pilot on the tile sees none, or a building replaced them.
     *
     * @param string $universe
     * @param integer $id
     * @param bool $deleteMissions
     * @return boolean
     */
    public static function npc_remove(string $universe, int $id, bool $deleteMissions = false): bool
    {
        debug(__METHOD__, func_get_args());

        $kids = array_map(static fn ($r) => (int) $r->kid, self::npc_live(universe: $universe, id: $id));
        self::npc_end(universe: $universe, kids: $kids, deleteMissions: $deleteMissions, sync: false);

        $db = MySqlDB::instance();
        $db->execute(sprintf('UPDATE %s_Maps SET `npc` = NULL, `npc_cloaked` = NULL, `npc_updated` = UTC_TIMESTAMP() WHERE id = ?', $universe), [
            'i', $id
        ]);

        return true;
    }

    /**
     * Update one NPC record's health (from the combat screen).
     *
     * @param string $universe
     * @param integer $kid
     * @param integer $hull
     * @param integer $armor
     * @param integer $shield
     * @return boolean
     */
    public static function npc_update_health(string $universe, int $kid, int $hull, int $armor, int $shield): bool
    {
        debug(__METHOD__, func_get_args());

        $db = MySqlDB::instance();
        $db->execute(sprintf('UPDATE %s_Test_Npcs SET `hull` = ?, `armor` = ?, `shield` = ?, `updated` = UTC_TIMESTAMP() WHERE kid = ?', $universe), [
            'iiii', $hull, $armor, $shield, $kid
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