-- Runs only on first Postgres init (empty volume).
CREATE DATABASE maps_reviews_testing;
GRANT ALL PRIVILEGES ON DATABASE maps_reviews_testing TO maps;
