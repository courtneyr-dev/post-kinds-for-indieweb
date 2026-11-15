SELECT SQL_CALC_FOUND_ROWS  wp_posts.ID
					 FROM wp_posts  LEFT JOIN wp_term_relationships ON (wp_posts.ID = wp_term_relationships.object_id)
					 WHERE 1=1  AND ( 
  wp_term_relationships.term_taxonomy_id IN (TERM_TAXONOMY_ID)
) AND ((wp_posts.post_type = 'post' AND (wp_posts.post_status = 'publish'))) AND NOT ( wp_posts.ID IN ( SELECT pkiw_public_tr.object_id FROM wp_term_relationships pkiw_public_tr WHERE pkiw_public_tr.term_taxonomy_id IN ( GOVERNED_TERM_TAXONOMY_IDS ) ) AND NOT ( wp_posts.post_status = 'publish' AND wp_posts.post_password = '' ) )
					 GROUP BY wp_posts.ID
					 ORDER BY wp_posts.post_date DESC
					 LIMIT 0, 12
