-- ============================================================================================
-- One-off cleanup of NPC IDs (nid) wrongly written by the pre-November-2026 importer.
--
-- The bug: fighting NPC X on a tile wrote X's nid onto EVERY record ever kept for that tile.
-- So a wrong nid always sits on a tile where its real owner was also recorded with that nid.
--
-- Rule, per universe:
--   owner of an nid = the NPC type it appears on across the most tiles (a tie = no owner, skipped).
--     Only the Feral Serpent changes picture; its 4 images count as one type.
--   clear the nid on records of any OTHER type, but only on tiles where the owner has a record
--     with that nid (direct evidence of the overwrite). Same nid on another type elsewhere is
--     left alone (IDs could be reused by later spawns).
--   clear every nid of 0 (the script couldn't read an ID).
--   never touch live records updated since the fix was deployed (2026-11-27 17:00 UTC).
-- Cleared records stay as sightings, just without an ID. Every change is saved to
-- nid_cleanup_backup first; STEP 4 undoes it.
--
-- Run in phpMyAdmin (MySQL 5.7), one STEP at a time. Needs the `nid` index on each
-- {Universe}_Test_Npcs table.
-- ============================================================================================


-- STEP 1: work out what would be cleared (changes no NPC data; builds 4 helper tables)

DROP TABLE IF EXISTS nidfix_counts, nidfix_owner, nidfix_owner_tiles, nidfix_clear;

CREATE TABLE nidfix_counts AS
SELECT 'Orion' AS uni, nid, IF(image LIKE 'opponents/feral_serpent%', 'opponents/feral_serpent', image) AS fam,
       COUNT(*) AS records, COUNT(DISTINCT id) AS tiles
  FROM Orion_Test_Npcs WHERE nid > 0 GROUP BY nid, fam
UNION ALL
SELECT 'Artemis', nid, IF(image LIKE 'opponents/feral_serpent%', 'opponents/feral_serpent', image),
       COUNT(*), COUNT(DISTINCT id)
  FROM Artemis_Test_Npcs WHERE nid > 0 GROUP BY 2, 3
UNION ALL
SELECT 'Pegasus', nid, IF(image LIKE 'opponents/feral_serpent%', 'opponents/feral_serpent', image),
       COUNT(*), COUNT(DISTINCT id)
  FROM Pegasus_Test_Npcs WHERE nid > 0 GROUP BY 2, 3;
ALTER TABLE nidfix_counts ADD INDEX (uni, nid);

CREATE TABLE nidfix_owner AS
SELECT c.uni, c.nid, MIN(c.fam) AS fam
  FROM nidfix_counts c
  JOIN (SELECT uni, nid, MAX(tiles) AS most FROM nidfix_counts GROUP BY uni, nid) m
    ON m.uni = c.uni AND m.nid = c.nid AND c.tiles = m.most
 GROUP BY c.uni, c.nid
HAVING COUNT(*) = 1;
ALTER TABLE nidfix_owner ADD PRIMARY KEY (uni, nid);

CREATE TABLE nidfix_owner_tiles AS
SELECT DISTINCT 'Orion' AS uni, t.nid, t.id FROM Orion_Test_Npcs t
  JOIN nidfix_owner o ON o.uni = 'Orion' AND o.nid = t.nid
 WHERE IF(t.image LIKE 'opponents/feral_serpent%', 'opponents/feral_serpent', t.image) = o.fam
UNION ALL
SELECT DISTINCT 'Artemis', t.nid, t.id FROM Artemis_Test_Npcs t
  JOIN nidfix_owner o ON o.uni = 'Artemis' AND o.nid = t.nid
 WHERE IF(t.image LIKE 'opponents/feral_serpent%', 'opponents/feral_serpent', t.image) = o.fam
UNION ALL
SELECT DISTINCT 'Pegasus', t.nid, t.id FROM Pegasus_Test_Npcs t
  JOIN nidfix_owner o ON o.uni = 'Pegasus' AND o.nid = t.nid
 WHERE IF(t.image LIKE 'opponents/feral_serpent%', 'opponents/feral_serpent', t.image) = o.fam;
ALTER TABLE nidfix_owner_tiles ADD INDEX (uni, nid, id);

CREATE TABLE nidfix_clear AS
SELECT 'Orion' AS uni, t.kid, t.nid, t.image, IF(t.nid = 0, 'nid 0', CONCAT('belongs to ', o.fam)) AS reason
  FROM Orion_Test_Npcs t
  LEFT JOIN nidfix_owner o ON o.uni = 'Orion' AND o.nid = t.nid
  LEFT JOIN nidfix_owner_tiles ot ON ot.uni = 'Orion' AND ot.nid = t.nid AND ot.id = t.id
 WHERE t.nid IS NOT NULL
   AND NOT (t.deleted IS NULL AND t.updated >= '2026-11-27 17:00:00')
   AND (t.nid = 0 OR (ot.id IS NOT NULL AND IF(t.image LIKE 'opponents/feral_serpent%', 'opponents/feral_serpent', t.image) <> o.fam))
UNION ALL
SELECT 'Artemis', t.kid, t.nid, t.image, IF(t.nid = 0, 'nid 0', CONCAT('belongs to ', o.fam))
  FROM Artemis_Test_Npcs t
  LEFT JOIN nidfix_owner o ON o.uni = 'Artemis' AND o.nid = t.nid
  LEFT JOIN nidfix_owner_tiles ot ON ot.uni = 'Artemis' AND ot.nid = t.nid AND ot.id = t.id
 WHERE t.nid IS NOT NULL
   AND NOT (t.deleted IS NULL AND t.updated >= '2026-11-27 17:00:00')
   AND (t.nid = 0 OR (ot.id IS NOT NULL AND IF(t.image LIKE 'opponents/feral_serpent%', 'opponents/feral_serpent', t.image) <> o.fam))
UNION ALL
SELECT 'Pegasus', t.kid, t.nid, t.image, IF(t.nid = 0, 'nid 0', CONCAT('belongs to ', o.fam))
  FROM Pegasus_Test_Npcs t
  LEFT JOIN nidfix_owner o ON o.uni = 'Pegasus' AND o.nid = t.nid
  LEFT JOIN nidfix_owner_tiles ot ON ot.uni = 'Pegasus' AND ot.nid = t.nid AND ot.id = t.id
 WHERE t.nid IS NOT NULL
   AND NOT (t.deleted IS NULL AND t.updated >= '2026-11-27 17:00:00')
   AND (t.nid = 0 OR (ot.id IS NOT NULL AND IF(t.image LIKE 'opponents/feral_serpent%', 'opponents/feral_serpent', t.image) <> o.fam));
ALTER TABLE nidfix_clear ADD INDEX (uni, kid);


-- STEP 2: look at it (read only; run each on its own)

-- 2a. totals per universe
SELECT uni, COUNT(*) AS records_to_clear, SUM(reason = 'nid 0') AS nid_zero FROM nidfix_clear GROUP BY uni;

-- 2b. which NPCs lose IDs, and to whom the IDs belong
SELECT uni, REPLACE(REPLACE(image, 'opponents/', ''), '.png', '') AS npc, reason, COUNT(*) AS records
  FROM nidfix_clear GROUP BY uni, image, reason ORDER BY records DESC;

-- 2c. one NPC's view, e.g. the Shadow
SELECT * FROM nidfix_clear WHERE image LIKE 'opponents/shadow%' ORDER BY uni, nid;


-- STEP 3: apply (backs up, then clears exactly the rows listed in nidfix_clear)

CREATE TABLE IF NOT EXISTS nid_cleanup_backup (uni VARCHAR(10), kid INT, nid INT, image VARCHAR(200), cleaned_at DATETIME);
INSERT INTO nid_cleanup_backup (uni, kid, nid, image, cleaned_at)
SELECT uni, kid, nid, image, UTC_TIMESTAMP() FROM nidfix_clear;

UPDATE Orion_Test_Npcs t   JOIN nidfix_clear c ON c.uni = 'Orion'   AND c.kid = t.kid SET t.nid = NULL WHERE t.nid = c.nid;
UPDATE Artemis_Test_Npcs t JOIN nidfix_clear c ON c.uni = 'Artemis' AND c.kid = t.kid SET t.nid = NULL WHERE t.nid = c.nid;
UPDATE Pegasus_Test_Npcs t JOIN nidfix_clear c ON c.uni = 'Pegasus' AND c.kid = t.kid SET t.nid = NULL WHERE t.nid = c.nid;

DROP TABLE nidfix_counts, nidfix_owner, nidfix_owner_tiles, nidfix_clear;


-- STEP 4 (only if ever needed): undo everything in the backup table

-- UPDATE Orion_Test_Npcs t   JOIN nid_cleanup_backup b ON b.uni = 'Orion'   AND b.kid = t.kid SET t.nid = b.nid;
-- UPDATE Artemis_Test_Npcs t JOIN nid_cleanup_backup b ON b.uni = 'Artemis' AND b.kid = t.kid SET t.nid = b.nid;
-- UPDATE Pegasus_Test_Npcs t JOIN nid_cleanup_backup b ON b.uni = 'Pegasus' AND b.kid = t.kid SET t.nid = b.nid;
