INSERT INTO windows_event_types (code, name) VALUES
('agent_exit', 'Agent Exited'),
('agent_uninstall', 'Agent Uninstall Started')
ON DUPLICATE KEY UPDATE name = VALUES(name);
