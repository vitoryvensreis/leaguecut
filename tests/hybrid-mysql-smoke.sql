-- Assertions fail with duplicate key violations or missing tables/FKs.
START TRANSACTION;
INSERT INTO users (id,full_name,email,password_hash,whatsapp,instagram,pix_key_enc)
VALUES (900001,'Test Clipador','hybrid_ci@example.invalid','ci-hash','85999999999','hybrid_ci','ci-encrypted-placeholder');
INSERT INTO campaigns (id,title,slug,description) VALUES (900001,'CI Campaign','hybrid-ci','ci');
INSERT INTO social_accounts (id,user_id,network,username,profile_url)
VALUES (900001,900001,'instagram','hybrid_ci','https://www.instagram.com/hybrid_ci/');
INSERT INTO clips (id,user_id,campaign_id,social_account_id,title,published_url)
VALUES (900001,900001,900001,900001,'CI Clip','https://www.instagram.com/reel/AbC1234/');
INSERT INTO clip_verification_state (clip_id,network,external_id,status)
VALUES (900001,'instagram','AbC1234','pending');
INSERT INTO clip_verification_observations (clip_id,source,status,observed_views)
VALUES (900001,'manual_evidence','manual_review',123);
INSERT INTO social_oauth_connections (social_account_id,provider,provider_user_id,access_token_enc,scopes)
VALUES (900001,'instagram','ci-remote','ci-encrypted-placeholder','instagram_business_basic');
-- No ledger entries are created by verification writes.
SET @ledger_count := (SELECT COUNT(*) FROM ledger_entries WHERE clip_id=900001);
SET @verified_views := (SELECT verified_views FROM clips WHERE id=900001);
DELIMITER //
CREATE PROCEDURE assert_hybrid_finance()
BEGIN
 IF @ledger_count <> 0 OR @verified_views <> 0 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Hybrid verification changed financial counts';
 END IF;
END//
DELIMITER ;
CALL assert_hybrid_finance();
DROP PROCEDURE assert_hybrid_finance;
ROLLBACK;
