-- Apply after 001 and 002 with package writers quiesced. The backfill is retryable.
CREATE TABLE IF NOT EXISTS maa_slug_registry_lock_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Internal identity of this lock projection row',
    scope_id BIGINT UNSIGNED NOT NULL COMMENT 'Scope owning the physical lock partition',
    scope_end TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'One for the permanent separator beyond all partitions in the Scope',
    binding_partition BIGINT UNSIGNED NOT NULL COMMENT 'Zero for Scope occupancy, otherwise the participating Binding ID',
    key_kind TINYINT UNSIGNED NOT NULL COMMENT 'Lower boundary zero, claim key one, upper boundary two',
    slug VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Exact Registry slug for a claim, otherwise an empty boundary value',
    registry_id BIGINT UNSIGNED NULL COMMENT 'Canonical Registry row ID for a projected claim key',
    owner_binding_id BIGINT UNSIGNED NULL COMMENT 'Binding owner of a projected claim key',
    PRIMARY KEY (id),
    UNIQUE KEY uk_registry_lock_partition (scope_id, scope_end, binding_partition, key_kind, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Physical Registry lock partitions and boundary keys';

INSERT INTO maa_slug_registry_lock_keys (scope_id, scope_end, binding_partition, key_kind, slug, registry_id, owner_binding_id)
SELECT src.scope_id, src.scope_end, src.binding_partition, src.key_kind, src.slug, src.registry_id, src.owner_binding_id
FROM (
    SELECT s.id AS scope_id, edge.scope_end, 0 AS binding_partition, edge.key_kind, '' AS slug, NULL AS registry_id, NULL AS owner_binding_id
    FROM maa_slug_scopes s
    CROSS JOIN (SELECT 0 AS scope_end, 0 AS key_kind UNION ALL SELECT 0, 2 UNION ALL SELECT 1, 2) edge
    UNION ALL
    SELECT b.scope_id, 0, b.id, edge.key_kind, '', NULL, NULL
    FROM maa_slug_bindings b CROSS JOIN (SELECT 0 AS key_kind UNION ALL SELECT 2) edge
    UNION ALL
    SELECT r.scope_id, 0, 0, 1, r.slug, r.id, r.binding_id FROM maa_slug_registry r
    UNION ALL
    SELECT r.scope_id, 0, r.binding_id, 1, r.slug, r.id, r.binding_id FROM maa_slug_registry r
    UNION ALL
    SELECT 0, 0, 0, 2, '', NULL, NULL
) src
ON DUPLICATE KEY UPDATE registry_id = src.registry_id, owner_binding_id = src.owner_binding_id;
