<?php
/*
Plugin Name: Post Generator
Description: Génère des articles WordPress à partir de mots-clés en utilisant l'API de ChatGPT.
Version: 1.0
Author: Votre Nom
*/

// Constantes pour les noms des options
define('PG_API_KEY_OPTION', 'pg_api_key');
define('PG_SITE_OBJECTIVE_OPTION', 'pg_site_objective');
define('PG_BLOG_OBJECTIVE_OPTION', 'pg_blog_objective');
define('PG_SITE_KEYWORDS_OPTION', 'pg_site_keywords');

// Fonction pour afficher la page d'options
function pg_options_page() {
  ?>
  <div class="wrap">
    <h1>Post Generator Settings</h1>
    <form method="post" action="options.php">
      <?php
      settings_fields('pg_options_group');
      do_settings_sections('pg_options_group');
      ?>
      <table class="form-table">
        <tr valign="top">
          <th scope="row">Clé d'API</th>
          <td><input type="text" name="pg_api_key" value="<?php echo esc_attr(get_option('pg_api_key')); ?>" /></td>
        </tr>
        <tr valign="top">
          <th scope="row">Objectif du site</th>
          <td><textarea name="pg_site_objective" rows="5" cols="50"><?php echo esc_attr(get_option('pg_site_objective')); ?></textarea></td>
        </tr>
        <tr valign="top">
          <th scope="row">Mots-clés du site</th>
          <td><input type="text" name="pg_site_keywords" value="<?php echo esc_attr(get_option('pg_site_keywords')); ?>" /></td>
        </tr>
        <tr valign="top">
          <th scope="row">Objectif du blog</th>
          <td><textarea name="pg_blog_objective" rows="5" cols="50"><?php echo esc_attr(get_option('pg_blog_objective')); ?></textarea></td>
        </tr>
      </table>
      <?php submit_button(); ?>
    </form>
  </div>
  <?php
}

// Fonction pour ajouter le menu d'administration
function pg_add_admin_menu() {
  add_menu_page('Post Generator', 'Post Generator', 'manage_options', 'post-generator', 'pg_options_page');
  add_submenu_page('post-generator', 'Générer un article', 'Générer un article', 'manage_options', 'pg-generate-article', 'pg_generate_article_interface');
}
add_action('admin_menu', 'pg_add_admin_menu');

// Fonction pour enregistrer les paramètres
function pg_register_settings() {
  register_setting('pg_options_group', PG_API_KEY_OPTION);
  register_setting('pg_options_group', PG_SITE_OBJECTIVE_OPTION);
  register_setting('pg_options_group', PG_BLOG_OBJECTIVE_OPTION);
  register_setting('pg_options_group', PG_SITE_KEYWORDS_OPTION);
}
add_action('admin_init', 'pg_register_settings');

// Fonction pour générer un article
function pg_generate_post($keywords) {
  $api_key = get_option(PG_API_KEY_OPTION);
  $site_objective = get_option(PG_SITE_OBJECTIVE_OPTION);
  $blog_objective = get_option(PG_BLOG_OBJECTIVE_OPTION);
  $site_keywords = get_option(PG_SITE_KEYWORDS_OPTION);

  // Objectif défini en dur avec formatage et nouvelles instructions
  $objective = '' .
  'Tu es un expert du référencement (SEO) et de la rédaction web.\n' . 
  'Ton objectif est de générer un article de blog WordPress pertinant en te basant sur les mots-clés de l\'article fournis par l\'utilisateur.\n' .
  'Cet article devra être conforme à l\'objectif du blog et à l\'objectif du site, reprenant les mots-clés de l\'article et du site fournis par l\'utilisateur.\n' .
  '\n' .
  'L\'article doit être structuré de la manière suivante :\n' .
  'Titre: [Titre de l\'article]\n' .
  'Contenu: [Contenu de l\'article]\n' .
  'Extrait: [Extrait de l\'article]\n' .
  'Étiquettes: [Liste des étiquettes séparées par des virgules]\n' .
  'Catégories: [Liste des catégories séparées par des virgules]\n' .
  '\n' .
  'Le titre doit faire maximum 66 caractères.\n' .
  'Le contenu doit comporter au moins un lien externe.\n' .
  'Le contenu doit faire au minimum 300 mots.\n' .
  'L\'extrait doit faire entre 120 et 156 caractères.';

  if (empty($api_key) || empty($site_objective) || empty($blog_objective)) {
    return ['content' => 'Clé API, objectif du site ou objectif du blog manquant.', 'response' => null];
  }

  $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
    'headers' => [
      'Authorization' => 'Bearer ' . sanitize_text_field($api_key),
      'Content-Type' => 'application/json',
    ],
    'body' => json_encode([
      'model' => 'gpt-3.5-turbo',
      'messages' => [
        ['role' => 'system', 'content' => $objective],
        ['role' => 'user', 'content' => "Objectif du site: " . sanitize_text_field($site_objective) . "\nObjectif du blog: " . sanitize_text_field($blog_objective) . "\nMots-clés du site: " . sanitize_text_field($site_keywords) . "\nMots-clés de l'article: " . sanitize_text_field($keywords)],
      ],
      'max_tokens' => 500,
    ]),
    'timeout' => 15,
  ]);

  if (is_wp_error($response)) {
    return ['content' => 'Erreur lors de la génération de l\'article : ' . $response->get_error_message(), 'response' => null];
  }

  $body = json_decode(wp_remote_retrieve_body($response), true);
  $content = $body['choices'][0]['message']['content'] ?? 'Aucun texte généré.';

  // Extraction des informations
  preg_match('/Titre: (.+?)\n/', $content, $title_matches);
  preg_match('/Contenu: (.+?)\nExtrait: /s', $content, $content_matches);
  preg_match('/Extrait: (.+?)\nÉtiquettes: /s', $content, $excerpt_matches);
  preg_match('/Étiquettes: (.+?)\nCatégories: /s', $content, $tags_matches);
  preg_match('/Catégories: (.+)/', $content, $categories_matches);

  $title = $title_matches[1] ?? 'Titre par défaut';
  $post_content = $content_matches[1] ?? '';
  $excerpt = $excerpt_matches[1] ?? '';
  $tags = isset($tags_matches[1]) ? explode(',', $tags_matches[1]) : [];
  $categories = isset($categories_matches[1]) ? explode(',', $categories_matches[1]) : [];

  // Création de l'article WordPress
  pg_create_post($title, $post_content, $excerpt, $tags, $categories);

  return ['content' => 'Article généré avec succès !', 'response' => $response];
}

// Fonction pour créer un article WordPress
function pg_create_post($title, $content, $excerpt, $tags, $categories) {
  $post_data = [
    'post_title'    => wp_strip_all_tags($title),
    'post_content'  => $content,
    'post_excerpt'  => $excerpt,
    'post_status'   => 'publish',
    'post_author'   => get_current_user_id(),
    'tags_input'    => $tags,
    'post_category' => $categories,
  ];

  // Insérer l'article et obtenir son ID
  $post_id = wp_insert_post($post_data);

  // Vérifier si l'insertion a réussi
  if (!is_wp_error($post_id)) {
    // Vérifier si le plugin Yoast SEO est actif
    if (is_plugin_active('wordpress-seo/wp-seo.php')) {
      // Pré-remplir la méta description de Yoast SEO
      if (!empty($excerpt)) {
        update_post_meta($post_id, '_yoast_wpseo_metadesc', $excerpt);
      }

      // Pré-remplir la requête cible de Yoast SEO avec le premier mot-clé
      if (!empty($tags) && is_array($tags)) {
        $first_keyword = trim($tags[0]);
        update_post_meta($post_id, '_yoast_wpseo_focuskw', $first_keyword);
      }
    }
  }
}

// Interface utilisateur pour générer des articles
function pg_generate_article_interface() {
  ?>
  <div class="wrap">
    <h1>Générer un nouvel article</h1>
    <form method="post">
      <table class="form-table">
        <tr valign="top">
          <th scope="row">Mots-clés de l'article</th>
          <td><input type="text" name="pg_article_keywords" /></td>
        </tr>
      </table>
      <?php submit_button('Générer l\'article'); ?>
    </form>
  </div>
  <?php

  if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $keywords = sanitize_text_field($_POST['pg_article_keywords']);
    $result = pg_generate_post($keywords);
    $content = $result['content'];
    $response = $result['response'];
    
    // Afficher la réponse brute
    echo '<pre>';
    print_r($response);
    echo '</pre>';
    
    if (strpos($content, 'Erreur') === false) {
      echo '<div class="updated"><p>' . esc_html($content) . '</p></div>';
    } else {
      echo '<div class="error"><p>' . esc_html($content) . '</p></div>';
    }
  }
}
