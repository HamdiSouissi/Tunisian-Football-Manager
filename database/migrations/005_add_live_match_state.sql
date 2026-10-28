-- État persistant du moteur interactif minute par minute.
ALTER TABLE `fixtures`
    ADD COLUMN `match_state` JSON NULL AFTER `statistics`;
