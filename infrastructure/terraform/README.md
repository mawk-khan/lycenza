# infrastructure/terraform

Empty by design in Phase 0A.

Per the Phase 0A stop gates, this checkpoint does not provision cloud
resources, configure production secrets, or connect to any real
environment. When a future phase introduces actual infrastructure
(a Postgres instance, object storage, container hosting, DNS, etc.),
it belongs here, split by environment (`environments/staging`,
`environments/production`) with remote state and no hand-edited
resources.
