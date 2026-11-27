<?php
declare(strict_types=1);

namespace Pardusmapper;

class NPC{
    public const CLOACKED = [
        'opponents/blood_amoeba.png',
        'opponents/ceylacennia.png',
        'opponents/cyborg_manta.png',
        'opponents/manifestation_developed.png',
        'opponents/dreadscorps.png',
        'opponents/drosera.png',
        'opponents/energy_minnow.png',
        'opponents/energy_sparker.png',
        'opponents/smuggler_escorted.png',
        'opponents/pirate_experienced.png',
        'opponents/pirate_famous.png',
        'opponents/frost_crystal.png',
        'opponents/gorefanglings.png',
        'opponents/gorefangling.png',
        'opponents/gorefang.png',
        'opponents/hidden_drug_stash.png',
        'opponents/pirate_inexperienced.png',
        'opponents/infected_creature.png',
        'opponents/smuggler_lone.png',
        'opponents/lucidi_squad.png',
        'opponents/nebula_mole.png',
        'opponents/nebula_serpent.png',
        'opponents/oblivion_vortex.png',
        'opponents/manifestation_ripe',
        'opponents/sarracenia.png',
        'opponents/slave_trader.png',
        'opponents/manifestation_verdant.png',
        'opponents/locust_hive.png',
        'opponents/vyrex_hatcher.png',
        'opponents/vyrex_assassin.png',
        'opponents/vyrex_stinger.png',
        'opponents/vyrex_mutant_mauler.png',
        'opponents/vyrex_larva.png',
        'opponents/pirate_captain.png',
        'opponents/starclaw.png',
    ];

    public const FOR_LOGGED_USERS = [
        'opponents/energy_sparker.png',
        'opponents/smuggler_escorted.png',
        'opponents/euryale.png',
        'opponents/euryale_swarmlings.png',
        'opponents/pirate_famous.png',
        'opponents/hidden_drug_stash.png',
        'opponents/smuggler_lone.png',
        'opponents/medusa.png',
        'opponents/medusa_swarmling.png',
        'opponents/solar_banshee.png',
        'opponents/stheno.png',
        'opponents/stheno_swarmling.png',
        'opponents/energybees.png',
        'opponents/x993_battlecruiser.png',
        'opponents/x993_mothership.png',
        'opponents/z15_fighter.png',
        'opponents/z15_repair_drone.png',
        'opponents/z15_scout.png',
        'opponents/z15_spacepad.png',
        'opponents/z16_fighter.png',
        'opponents/z16_repair_drone.png',
        //'opponents/vyrex_assassin.png',
        //'opponents/vyrex_larva.png',
        //'opponents/vyrex_mutant_mauler.png',
        //'opponents/vyrex_stinger.png',
        //'opponents/vyrex_hatcher.png',
    ];

    /**
     * Rare NPCs whose sighting history is shown (combined across all universes)
     * on the sector map for logged-in users. image => display name.
     * To track another NPC, add its image from Pardus_Npcs here.
     */
    public const HISTORY = [
        'opponents/feral_serpent.png' => 'Feral Serpent',
        'opponents/feral_serpent_1.png' => 'Feral Serpent',
        'opponents/feral_serpent_2.png' => 'Feral Serpent',
        'opponents/feral_serpent_3.png' => 'Feral Serpent',
        'opponents/shadow.png' => 'Shadow',
        'opponents/hidden_drug_stash.png' => 'Hidden Drug Stash',
        'opponents/cyborg_corebreaker.png' => 'Cyborg Corebreaker',
        'opponents/pirate_captain.png' => 'Pirate Captain',
    ];

    /**
     * Tracked NPCs grouped by display name: slug => ['name' => ..., 'images' => [...]]
     * (Feral Serpent variants share one entry). Used by the Rare NPC Stats page.
     */
    public static function history_groups(): array
    {
        $groups = [];
        foreach (self::HISTORY as $image => $name) {
            $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $name));
            $groups[$slug]['name'] = $name;
            $groups[$slug]['images'][] = $image;
        }

        return $groups;
    }

    /** Records of the same NPC on the same tile less than this many seconds apart are one sighting. */
    public const SIGHTING_GAP = 7200;

    /**
     * Decide which NPC records are separate sightings.
     *
     * A record is added each time an NPC is first seen on a tile. Every move to a different tile
     * is real movement and counts. But an NPC that drops out of view and is seen again on the
     * same tile (cloaking, pilots seeing the tile from different positions) gets a new record
     * each time. These tracked NPCs have long respawn times, so records of the same NPC on the
     * same tile less than 2 hours apart are treated as one sighting. The window rolls: each
     * repeat extends it.
     *
     * Same NPC = same universe and name (Feral Serpent variants are one name); the NPC ID is not
     * used because it is only captured when a pilot stands on the tile.
     *
     * Adds ->ts (UTC epoch of `spotted`) and ->counted (false for a same-tile repeat) to each row.
     * Rows must be oldest first, as DB::npc_sightings() returns them.
     */
    public static function mark_sightings(array $rows): array
    {
        $lastOnTile = [];

        foreach ($rows as $r) {
            $r->ts = (int) strtotime($r->spotted . ' UTC');
            $key = implode('|', [$r->uni, self::HISTORY[$r->image] ?? $r->image, $r->sector, $r->x, $r->y]);
            $prev = $lastOnTile[$key] ?? null;
            $r->counted = is_null($prev) || ($r->ts - $prev) >= self::SIGHTING_GAP;
            $lastOnTile[$key] = $r->ts;
        }

        return $rows;
    }

    public const SINGLE = [
        'opponents/shadow.png',
        'opponents/feral_serpent.png',
    ];

    public const HACK = [
        'opponents/shadow.png',
        'opponents/pirate_experienced.png',
        'opponents/energybees.png',
    ];

    public const NON_BLOCKING = [
        'opponents/slave_trader.png',
        'opponents/smuggler_lone.png',
        'opponents/smuggler_escorted.png',
        //'foreground/wormhole.png',
        'opponents/gorefanglings.png',
        'opponents/gorefang.png',
        'opponents/nebula_mole.png',
        'opponents/hidden_drug_stash.png',
        'opponents/space_clam.png',
        'opponents/preywinder.png',
        'opponents/glowprawn.png',
        'opponents/starclaw.png',
        'opponents/eulerian.png',
        'opponents/vyrex_hatcher.png',
        'opponents/vyrex_assassin.png',
        'opponents/vyrex_stinger.png',
        'opponents/vyrex_mutant_mauler.png',
        'opponents/vyrex_larva.png',
    ];

    public const MOBILE = [
        // These NPC move around but may have limited count, need to not show cloaked location if another is spotted within the same range(new query needed to find ranged matches) NOTED
        'opponents/space_dragon_queen.png',
        'opponents/cyborg_manta.png',
        'opponents/lucidi_squad.png',
        'opponents/pirate_famous.png',
        'opponents/pirate_captain.png',
        'opponents/preywinder.png',
        'opponents/vyrex_mutant_mauler.png',
    ];

    /**
     * Only Vyrex and Pirates share a tile with another NPC; Vyrex can also stack (many of the same
     * type on one tile). Any other NPC seen on a tile replaces the non-sharing NPC recorded there.
     */
    public static function shares_tile(string $image): bool
    {
        return (bool) preg_match('#^opponents/(vyrex_|pirate_)#', $image);
    }

    /**
     * Vyrex can cloak, and an uncloaked Vyrex can be hidden under a bigger NPC on the nav screen,
     * so a Vyrex record is only ended by a pilot on its tile (or a kill), never from another tile.
     */
    public static function is_vyrex(string $image): bool
    {
        return str_starts_with($image, 'opponents/vyrex_');
    }

    public static function cloaked() {
        $cloaked = self::CLOACKED;
        sort($cloaked);

        return $cloaked;
    }


    public static function single() {
        $single = self::SINGLE;
        sort($single);

        return $single;
    }

    public static function hack() {
        $hack = self::HACK;
        sort($hack);

        return $hack;
    }

    public static function nonblocking() {
        $nonblocking = self::NON_BLOCKING;
        sort($nonblocking);

        return $nonblocking;
    }

    public static function mobile() {
        $mobile = self::MOBILE;
        sort($mobile);

        return $mobile;
    }

    public static function for_logged_users() {
        $for_logged_users = self::FOR_LOGGED_USERS;
        sort($for_logged_users);

        return $for_logged_users;
    }
}