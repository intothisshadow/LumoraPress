<?php

/**
 * How an *Importer class should handle a row whose external id was already imported by a previous batch of the same source (LPP-004).
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Services\Import;

/**
 * A null $existingContentMode argument on an *Importer's import() method
 * (the default everywhere) means "always create a new row" — today's
 * behavior, unchanged, since a normal first-time import never has an
 * existing row to find in the first place and this codebase's own
 * "one import at a time, remove before re-running" guard has always
 * made this the only reachable path anyway. Skip/Overwrite only matter
 * once a caller (WordPressImportService, for a re-import against a
 * source it already imported once before) opts in explicitly.
 */
enum ExistingContentMode
{
    /**
     * Reuse the existing local row unchanged — nothing is created or
     * updated, and the reused row is *not* re-recorded under the new
     * batch (the same "a reused row wasn't created by this batch"
     * invariant UserImporter::importOrReuse() already established),
     * so a later "remove everything this batch created" can never
     * delete content an earlier batch still owns.
     */
    case Skip;

    /**
     * Update the existing local row's fields in place with the freshly
     * imported values, keeping its local id — still not re-recorded
     * under the new batch, for the same ownership reason as Skip.
     */
    case Overwrite;
}
