
-- migrate:up

INSERT INTO "if__deployments" ("doc")
VALUES ('{"uuid":"0a73e3e4-f1a9-4f1f-9248-932c769c9a6d","service":"ares_gov_cz","name":"ares.gov.cz","description":"Registr ekonomických subjektů.","interfaces":[{"connector":"api", "host":"https://ares.gov.cz"}]}')
ON CONFLICT ("uuid") DO UPDATE SET
    "doc" = EXCLUDED."doc";

-- migrate:down

DELETE FROM "if__deployments"
WHERE "uuid" = '0a73e3e4-f1a9-4f1f-9248-932c769c9a6d';

