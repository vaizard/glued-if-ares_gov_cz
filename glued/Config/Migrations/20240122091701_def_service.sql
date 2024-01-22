-- migrate:up

INSERT INTO `t_if__services` (`c_uuid`, `c_data`)
VALUES (uuid_to_bin("39542e95-db70-4fd1-bba8-2cb52870dffd", true), '{"remote": "https://ares.gov.cz", "service": "ares_gov_cz", "deployment": "ares.gov.cz"}')
ON DUPLICATE KEY UPDATE `c_data` = VALUES(`c_data`);

-- migrate:down

