-- Remove the unused supervisory role from the assignable product surface.
-- Existing assignments would be preserved; this installation has none.

UPDATE roles r
SET r.deleted_at = CURRENT_TIMESTAMP
WHERE r.slug = 'manager' AND r.is_system = 1
  AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.role_id = r.id);

INSERT IGNORE INTO schema_migrations (migration)
VALUES ('20260706_003_remove_supervisory_surface');

