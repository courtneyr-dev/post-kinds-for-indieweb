SELECT SQL_CALC_FOUND_ROWS  wp_posts.ID
					 FROM wp_posts  LEFT JOIN wp_term_relationships ON (wp_posts.ID = wp_term_relationships.object_id)
					 WHERE N=N  AND ( 
  wp_term_relationships.term_taxonomy_id IN (N)
) AND ((wp_posts.post_type = 'post' AND (wp_posts.post_status = 'publish')))
					 GROUP BY wp_posts.ID
					 ORDER BY wp_posts.post_date DESC
					 LIMIT N, N
