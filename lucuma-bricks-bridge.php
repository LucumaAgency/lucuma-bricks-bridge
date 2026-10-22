<?php
/**
 * Plugin Name: Lucuma Bricks Bridge
 * Description: Endpoint REST para leer y escribir el contenido Bricks de una página (y el header/footer de las plantillas) desde fuera (importa JSON en formato bricksCopiedElements). Con respaldo automático y restauración. Desde 1.1.0: tipo y condiciones de plantillas, crear plantillas, clases globales, noindex por página y vista previa privada de borradores.
 * Version: 1.1.0
 * Author: Lucuma Agency
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Lucuma_Bricks_Bridge {

	const NS            = 'lucuma-bricks/v1';
	const META_CONTENT  = '_bricks_page_content_2';
	/** Clave antigua: algunas plantillas (p. ej. footers creados con Bricks viejo) siguen ahí. */
	const META_CONTENT_LEGACY = '_bricks_page_content';
	/** Áreas de plantilla (header y footer de Bricks guardan sus elementos aparte). */
	const META_AREAS    = [ 'header' => '_bricks_page_header_2', 'footer' => '_bricks_page_footer_2' ];
	const META_MODE     = '_bricks_editor_mode';
	const META_BACKUPS  = '_lucuma_bricks_backups';
	const OPT_CLASSES   = 'bricks_global_classes';
	const MAX_BACKUPS   = 5;
	const VERSION       = '1.1.0';
	/** Plantillas: tipo y ajustes (condiciones) tal como los guarda Bricks. */
	const META_TPL_TYPE     = '_bricks_template_type';
	const META_TPL_SETTINGS = '_bricks_template_settings';
	const META_TPL_BACKUPS  = '_lucuma_tpl_backups';
	const OPT_CLASS_BACKUPS = 'lucuma_bricks_classes_backups';
	const META_NOINDEX      = '_lucuma_noindex';
	const OPT_PREVIEW       = 'lucuma_bricks_preview';
	const PREVIEW_ARG       = 'lucuma_preview';

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_filter( 'wp_robots', [ __CLASS__, 'robots' ] );
		// Vista previa privada: solo actúa si la URL trae un token válido.
		add_filter( 'posts_results', [ __CLASS__, 'preview_posts' ], 10, 2 );
		add_filter( 'bricks/active_templates', [ __CLASS__, 'preview_templates' ], 999, 3 );
		add_action( 'template_redirect', [ __CLASS__, 'preview_headers' ], 0 );
	}

	public static function routes() {
		$perm = function ( $req ) {
			$id = (int) $req->get_param( 'id' );
			return $id ? current_user_can( 'edit_post', $id ) : current_user_can( 'edit_pages' );
		};
		register_rest_route( self::NS, '/health', [
			'methods' => 'GET', 'permission_callback' => '__return_true',
			'callback' => function () {
				return [ 'ok' => true, 'bricks' => defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : null, 'plugin' => self::VERSION ];
			},
		] );
		register_rest_route( self::NS, '/pages/(?P<id>\d+)', [
			'methods' => 'GET', 'permission_callback' => $perm, 'callback' => [ __CLASS__, 'get_page' ],
		] );
		register_rest_route( self::NS, '/pages/(?P<id>\d+)/content', [
			'methods' => 'POST', 'permission_callback' => $perm, 'callback' => [ __CLASS__, 'set_content' ],
		] );
		register_rest_route( self::NS, '/pages/(?P<id>\d+)/restore', [
			'methods' => 'POST', 'permission_callback' => $perm, 'callback' => [ __CLASS__, 'restore' ],
		] );
		register_rest_route( self::NS, '/pages/(?P<id>\d+)/css', [
			'methods' => 'POST', 'permission_callback' => $perm,
			'callback' => function ( $req ) {
				$id   = (int) $req['id'];
				$area = self::area( $req );
				if ( is_wp_error( $area ) ) return $area;
				$c  = get_post_meta( $id, self::key_for( $id, $area ), true );
				if ( ! is_array( $c ) ) return new WP_Error( 'not_found', 'Sin contenido Bricks', [ 'status' => 404 ] );
				return [ 'ok' => true, 'id' => $id, 'area' => $area, 'css' => self::regenerate_css( $id, $c, $area ) ];
			},
		] );
		register_rest_route( self::NS, '/pages', [
			'methods' => 'POST', 'permission_callback' => $perm, 'callback' => [ __CLASS__, 'create_page' ],
		] );
		register_rest_route( self::NS, '/templates', [
			[ 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => [ __CLASS__, 'list_templates' ] ],
			[ 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => [ __CLASS__, 'create_template' ] ],
		] );
		register_rest_route( self::NS, '/templates/(?P<id>\d+)/settings', [
			[ 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => [ __CLASS__, 'get_template_settings' ] ],
			[ 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => [ __CLASS__, 'set_template_settings' ] ],
		] );
		register_rest_route( self::NS, '/templates/(?P<id>\d+)/settings/restore', [
			'methods' => 'POST', 'permission_callback' => $perm, 'callback' => [ __CLASS__, 'restore_template_settings' ],
		] );
		$admin = function () { return current_user_can( 'manage_options' ); };
		register_rest_route( self::NS, '/classes', [
			[ 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function () { return get_option( self::OPT_CLASSES, [] ); } ],
			[ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'upsert_classes' ] ],
		] );
		register_rest_route( self::NS, '/pages/(?P<id>\d+)/noindex', [
			'methods' => 'POST', 'permission_callback' => $perm, 'callback' => [ __CLASS__, 'set_noindex' ],
		] );
		register_rest_route( self::NS, '/preview', [
			[ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'create_preview' ] ],
			[ 'methods' => 'DELETE', 'permission_callback' => $admin, 'callback' => function () {
				delete_option( self::OPT_PREVIEW ); return [ 'ok' => true, 'revoked' => true ];
			} ],
		] );
	}

	/* ---------- Plantillas: crear, tipo y condiciones ---------- */

	/** POST /templates {title, type, status} crea una plantilla vacía de Bricks. */
	public static function create_template( $req ) {
		$b = $req->get_json_params();
		$title = sanitize_text_field( $b['title'] ?? '' );
		$type  = sanitize_key( $b['type'] ?? '' );
		if ( ! $title || ! $type ) return new WP_Error( 'bad_args', 'Faltan "title" y "type"', [ 'status' => 400 ] );
		$id = wp_insert_post( [ 'post_type' => 'bricks_template', 'post_title' => $title,
			'post_status' => in_array( $b['status'] ?? 'publish', [ 'publish', 'draft' ], true ) ? ( $b['status'] ?? 'publish' ) : 'publish' ], true );
		if ( is_wp_error( $id ) ) return $id;
		update_post_meta( $id, self::META_TPL_TYPE, $type );
		update_post_meta( $id, self::META_MODE, 'bricks' );
		return [ 'ok' => true, 'id' => $id, 'title' => $title, 'type' => $type ];
	}

	public static function get_template_settings( $req ) {
		$id = (int) $req['id'];
		$p  = get_post( $id );
		if ( ! $p || $p->post_type !== 'bricks_template' ) return new WP_Error( 'not_found', 'Plantilla no encontrada', [ 'status' => 404 ] );
		$s = get_post_meta( $id, self::META_TPL_SETTINGS, true );
		$bk = get_post_meta( $id, self::META_TPL_BACKUPS, true );
		return [ 'id' => $id, 'title' => $p->post_title, 'type' => get_post_meta( $id, self::META_TPL_TYPE, true ),
			'settings' => is_array( $s ) ? $s : new stdClass(),
			'backups' => is_array( $bk ) ? array_map( function ( $x ) { return [ 'key' => $x['key'], 'date' => $x['date'], 'nota' => $x['nota'] ]; }, $bk ) : [] ];
	}

	/**
	 * POST /templates/{id}/settings
	 * {type?, conditions?: [...] (reemplaza templateConditions; [] las quita), settings?: {...} (reemplaza todo), nota?}
	 * Guarda un respaldo del tipo y los ajustes anteriores.
	 */
	public static function set_template_settings( $req ) {
		$id = (int) $req['id'];
		$p  = get_post( $id );
		if ( ! $p || $p->post_type !== 'bricks_template' ) return new WP_Error( 'not_found', 'Plantilla no encontrada', [ 'status' => 404 ] );
		$b = $req->get_json_params();
		if ( ! is_array( $b ) ) return new WP_Error( 'bad_json', 'El body debe ser JSON', [ 'status' => 400 ] );
		$old_type = get_post_meta( $id, self::META_TPL_TYPE, true );
		$old      = get_post_meta( $id, self::META_TPL_SETTINGS, true );
		$old      = is_array( $old ) ? $old : [];
		$new      = $old;
		if ( isset( $b['settings'] ) ) {
			if ( ! is_array( $b['settings'] ) ) return new WP_Error( 'bad_settings', '"settings" debe ser un objeto', [ 'status' => 400 ] );
			$new = $b['settings'];
		}
		if ( array_key_exists( 'conditions', $b ) ) {
			if ( ! is_array( $b['conditions'] ) ) return new WP_Error( 'bad_conditions', '"conditions" debe ser una lista', [ 'status' => 400 ] );
			if ( $b['conditions'] ) $new['templateConditions'] = array_values( $b['conditions'] );
			else unset( $new['templateConditions'] );
		}
		$key = self::tpl_backup( $id, $old_type, $old, sanitize_text_field( $b['nota'] ?? 'antes de cambiar ajustes' ) );
		if ( isset( $b['type'] ) ) update_post_meta( $id, self::META_TPL_TYPE, sanitize_key( $b['type'] ) );
		if ( $new ) update_post_meta( $id, self::META_TPL_SETTINGS, wp_slash( $new ) );
		else delete_post_meta( $id, self::META_TPL_SETTINGS );
		wp_update_post( [ 'ID' => $id ] );
		self::flush_bricks_caches();
		return [ 'ok' => true, 'id' => $id, 'type' => get_post_meta( $id, self::META_TPL_TYPE, true ),
			'settings' => $new ?: new stdClass(), 'backup' => $key ];
	}

	public static function restore_template_settings( $req ) {
		$id = (int) $req['id'];
		$bk = get_post_meta( $id, self::META_TPL_BACKUPS, true );
		if ( ! is_array( $bk ) || ! $bk ) return new WP_Error( 'no_backups', 'Sin respaldos', [ 'status' => 404 ] );
		$b = $req->get_json_params();
		$want = is_array( $b ) && ! empty( $b['backup'] ) ? $b['backup'] : end( $bk )['key'];
		$found = null;
		foreach ( $bk as $x ) if ( $x['key'] === $want ) $found = $x;
		if ( ! $found ) return new WP_Error( 'bad_key', 'Respaldo no encontrado', [ 'status' => 404 ] );
		$cur = get_post_meta( $id, self::META_TPL_SETTINGS, true );
		self::tpl_backup( $id, get_post_meta( $id, self::META_TPL_TYPE, true ), is_array( $cur ) ? $cur : [], 'antes de restaurar ' . $want );
		if ( $found['type'] !== '' ) update_post_meta( $id, self::META_TPL_TYPE, $found['type'] );
		if ( $found['settings'] ) update_post_meta( $id, self::META_TPL_SETTINGS, wp_slash( $found['settings'] ) );
		else delete_post_meta( $id, self::META_TPL_SETTINGS );
		wp_update_post( [ 'ID' => $id ] );
		self::flush_bricks_caches();
		return [ 'ok' => true, 'restored' => $want ];
	}

	private static function tpl_backup( $id, $type, $settings, $nota ) {
		$bk = get_post_meta( $id, self::META_TPL_BACKUPS, true );
		if ( ! is_array( $bk ) ) $bk = [];
		$key = gmdate( 'Ymd-His' ) . '-' . substr( md5( wp_rand() ), 0, 4 );
		$bk[] = [ 'key' => $key, 'date' => current_time( 'mysql' ), 'nota' => $nota, 'type' => (string) $type, 'settings' => $settings ];
		update_post_meta( $id, self::META_TPL_BACKUPS, wp_slash( array_slice( $bk, -10 ) ) );
		return $key;
	}

	/** Bricks guarda en caché qué plantillas aplican; se limpia tras cambiar condiciones. */
	private static function flush_bricks_caches() {
		delete_transient( 'bricks_template_conditions' );
		if ( function_exists( 'wp_cache_flush' ) ) wp_cache_flush();
	}

	/* ---------- Clases globales (actualizar, no solo añadir) ---------- */

	/** POST /classes {classes:[{id,name,settings}], nota?} reemplaza por id las existentes y añade las nuevas. */
	public static function upsert_classes( $req ) {
		$b = $req->get_json_params();
		$in = is_array( $b ) && isset( $b['classes'] ) && is_array( $b['classes'] ) ? $b['classes'] : null;
		if ( $in === null ) return new WP_Error( 'no_classes', 'Falta "classes"', [ 'status' => 400 ] );
		$opt = get_option( self::OPT_CLASSES, [] );
		if ( ! is_array( $opt ) ) $opt = [];
		$bk = get_option( self::OPT_CLASS_BACKUPS, [] );
		if ( ! is_array( $bk ) ) $bk = [];
		$bk[] = [ 'date' => current_time( 'mysql' ), 'nota' => sanitize_text_field( $b['nota'] ?? '' ), 'classes' => $opt ];
		update_option( self::OPT_CLASS_BACKUPS, array_slice( $bk, -5 ), false );
		$pos = [];
		foreach ( $opt as $i => $c ) if ( isset( $c['id'] ) ) $pos[ $c['id'] ] = $i;
		$added = 0; $updated = 0;
		foreach ( $in as $c ) {
			if ( empty( $c['id'] ) || empty( $c['name'] ) ) continue;
			if ( isset( $pos[ $c['id'] ] ) ) { $opt[ $pos[ $c['id'] ] ] = $c; $updated++; }
			else { $opt[] = $c; $pos[ $c['id'] ] = count( $opt ) - 1; $added++; }
		}
		update_option( self::OPT_CLASSES, $opt );
		return [ 'ok' => true, 'added' => $added, 'updated' => $updated, 'total' => count( $opt ) ];
	}

	/* ---------- Noindex por página ---------- */

	public static function set_noindex( $req ) {
		$id = (int) $req['id'];
		if ( ! get_post( $id ) ) return new WP_Error( 'not_found', 'Página no encontrada', [ 'status' => 404 ] );
		$b = $req->get_json_params();
		$on = ! is_array( $b ) || ! array_key_exists( 'noindex', $b ) || (bool) $b['noindex'];
		if ( $on ) update_post_meta( $id, self::META_NOINDEX, 1 ); else delete_post_meta( $id, self::META_NOINDEX );
		return [ 'ok' => true, 'id' => $id, 'noindex' => $on ];
	}

	public static function robots( $robots ) {
		$id = is_singular() ? get_queried_object_id() : 0;
		if ( ( $id && get_post_meta( $id, self::META_NOINDEX, true ) ) || self::preview_valid() ) {
			$robots['noindex'] = true; $robots['nofollow'] = true;
			unset( $robots['index'], $robots['follow'], $robots['max-image-preview'] );
		}
		return $robots;
	}

	/* ---------- Vista previa privada de borradores ---------- */

	/**
	 * POST /preview {hours?: 24} crea un token (se guarda solo su hash) válido por N horas (máx. 168).
	 * Con ?lucuma_preview=TOKEN se ve cualquier borrador (por ?page_id= o ?p=) y se pueden forzar
	 * plantillas de Bricks: &lb_header=ID &lb_footer=ID &lb_content=ID (o cualquier clave lb_<área>).
	 * La respuesta siempre lleva noindex y no se guarda en caché. DELETE /preview lo revoca.
	 */
	public static function create_preview( $req ) {
		$b = $req->get_json_params();
		$h = max( 1, min( 168, (int) ( is_array( $b ) && isset( $b['hours'] ) ? $b['hours'] : 24 ) ) );
		$token = wp_generate_password( 32, false, false );
		$exp = time() + $h * HOUR_IN_SECONDS;
		update_option( self::OPT_PREVIEW, [ 'hash' => hash( 'sha256', $token ), 'expires' => $exp ], false );
		return [ 'ok' => true, 'token' => $token, 'expires' => gmdate( 'c', $exp ), 'arg' => self::PREVIEW_ARG ];
	}

	private static function preview_valid() {
		static $valid = null;
		if ( $valid !== null ) return $valid;
		$valid = false;
		if ( empty( $_GET[ self::PREVIEW_ARG ] ) ) return $valid;
		$o = get_option( self::OPT_PREVIEW );
		if ( ! is_array( $o ) || empty( $o['hash'] ) || time() > (int) $o['expires'] ) return $valid;
		$t = sanitize_text_field( wp_unslash( $_GET[ self::PREVIEW_ARG ] ) );
		$valid = hash_equals( $o['hash'], hash( 'sha256', $t ) );
		return $valid;
	}

	/** Deja ver el borrador pedido en la consulta principal (como hace "Public Post Preview"). */
	public static function preview_posts( $posts, $query ) {
		if ( is_admin() || ! $query->is_main_query() || count( $posts ) !== 1 || ! self::preview_valid() ) return $posts;
		if ( in_array( $posts[0]->post_status, [ 'draft', 'pending', 'future', 'private' ], true ) ) $posts[0]->post_status = 'publish';
		return $posts;
	}

	/** Con token válido, ?lb_header=ID, ?lb_footer=ID, ?lb_content=ID… sustituyen las plantillas activas. */
	public static function preview_templates( $active, $post_id = 0, $content_type = '' ) {
		if ( ! is_array( $active ) || ! self::preview_valid() ) return $active;
		foreach ( $_GET as $k => $v ) {
			if ( strpos( $k, 'lb_' ) !== 0 ) continue;
			$area = sanitize_key( substr( $k, 3 ) );
			$tid  = (int) $v;
			if ( $area && $tid && get_post_type( $tid ) === 'bricks_template' ) $active[ $area ] = $tid;
		}
		return $active;
	}

	public static function preview_headers() {
		if ( ! self::preview_valid() ) return;
		if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
	}

	/** Clave donde vive el contenido Bricks de este post (la nueva si hay algo, si no la antigua). */
	private static function content_key( $id ) {
		$c = get_post_meta( $id, self::META_CONTENT, true );
		if ( is_array( $c ) && $c ) return self::META_CONTENT;
		$l = get_post_meta( $id, self::META_CONTENT_LEGACY, true );
		if ( is_array( $l ) && $l ) return self::META_CONTENT_LEGACY;
		return self::META_CONTENT;
	}

	/** 'content' (por defecto), 'header' o 'footer'. Devuelve WP_Error si es otra cosa. */
	private static function area( $req ) {
		$a = $req->get_param( 'area' );
		if ( $a === null ) { $b = $req->get_json_params(); $a = is_array( $b ) && isset( $b['area'] ) ? $b['area'] : 'content'; }
		if ( $a !== 'content' && ! isset( self::META_AREAS[ $a ] ) ) return new WP_Error( 'bad_area', 'area debe ser content, header o footer', [ 'status' => 400 ] );
		return $a;
	}

	private static function key_for( $id, $area ) {
		return $area === 'content' ? self::content_key( $id ) : self::META_AREAS[ $area ];
	}

	/* ---------- GET /templates ---------- */
	public static function list_templates() {
		$q = get_posts( [ 'post_type' => 'bricks_template', 'post_status' => 'any', 'numberposts' => 200 ] );
		return array_map( function ( $p ) {
			$n = [];
			foreach ( array_merge( [ 'content' => self::content_key( $p->ID ) ], self::META_AREAS ) as $a => $k ) {
				$c = get_post_meta( $p->ID, $k, true ); $n[ $a ] = is_array( $c ) ? count( $c ) : 0;
			}
			return [ 'id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status,
				'type' => get_post_meta( $p->ID, '_bricks_template_type', true ), 'elements' => $n ];
		}, $q );
	}

	/* ---------- GET /pages/{id} ---------- */
	public static function get_page( $req ) {
		$id   = (int) $req['id'];
		$post = get_post( $id );
		if ( ! $post ) return new WP_Error( 'not_found', 'Página no encontrada', [ 'status' => 404 ] );
		$area = self::area( $req );
		if ( is_wp_error( $area ) ) return $area;
		$key     = self::key_for( $id, $area );
		$content = get_post_meta( $id, $key, true );
		$backups = get_post_meta( $id, self::META_BACKUPS, true );
		return [
			'id'       => $id,
			'title'    => $post->post_title,
			'slug'     => $post->post_name,
			'status'   => $post->post_status,
			'link'     => get_permalink( $id ),
			'mode'     => get_post_meta( $id, self::META_MODE, true ),
			'post_type' => $post->post_type,
			'area'     => $area,
			'elements' => is_array( $content ) ? count( $content ) : 0,
			'content'  => is_array( $content ) ? $content : [],
			'backups'  => is_array( $backups ) ? array_map( function ( $b ) { return [ 'key' => $b['key'], 'date' => $b['date'], 'elements' => count( $b['content'] ), 'nota' => $b['nota'], 'area' => $b['area'] ?? 'content' ]; }, $backups ) : [],
		];
	}

	/* ---------- POST /pages/{id}/content ---------- */
	public static function set_content( $req ) {
		$id   = (int) $req['id'];
		$post = get_post( $id );
		if ( ! $post ) return new WP_Error( 'not_found', 'Página no encontrada', [ 'status' => 404 ] );

		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) return new WP_Error( 'bad_json', 'El body debe ser JSON', [ 'status' => 400 ] );

		// Acepta el JSON de "copiar elementos" de Bricks tal cual, o {content:[...]}.
		$elements = isset( $body['content'] ) && is_array( $body['content'] ) ? $body['content'] : null;
		if ( $elements === null ) return new WP_Error( 'no_content', 'Falta "content" (array de elementos)', [ 'status' => 400 ] );

		$mode    = isset( $body['mode'] ) && $body['mode'] === 'append' ? 'append' : 'replace';
		$regen   = ! isset( $body['regenerate_ids'] ) || (bool) $body['regenerate_ids'];
		$nota    = isset( $body['nota'] ) ? sanitize_text_field( $body['nota'] ) : '';
		$classes = isset( $body['globalClasses'] ) && is_array( $body['globalClasses'] ) ? $body['globalClasses'] : [];

		$check = self::validate( $elements );
		if ( is_wp_error( $check ) ) return $check;

		$area = self::area( $req );
		if ( is_wp_error( $area ) ) return $area;
		$key     = self::key_for( $id, $area );
		$current = get_post_meta( $id, $key, true );
		$current = is_array( $current ) ? $current : [];

		if ( $regen ) $elements = self::regenerate_ids( $elements, $current );
		$classes_added = self::merge_global_classes( $classes );

		$new = $mode === 'append' ? array_merge( $current, $elements ) : $elements;

		$backup_key = self::backup( $id, $current, $nota ?: 'antes de ' . $mode, $area );

		update_post_meta( $id, $key, wp_slash( $new ) );
		if ( $area === 'content' ) update_post_meta( $id, self::META_MODE, 'bricks' );
		wp_update_post( [ 'ID' => $id ] ); // toca modified y limpia cachés de objeto (los hooks de Bricks pueden vaciar el CSS: por eso se regenera DESPUÉS)
		$css = self::regenerate_css( $id, $new, $area );

		return [
			'ok'            => true,
			'id'            => $id,
			'link'          => get_permalink( $id ),
			'mode'          => $mode,
			'area'          => $area,
			'elements'      => count( $new ),
			'roots'         => count( array_filter( $new, function ( $e ) { return ( $e['parent'] ?? 0 ) === 0 || $e['parent'] === '0'; } ) ),
			'ids_regenerated' => $regen,
			'classes_added' => $classes_added,
			'backup'        => $backup_key,
			'css'           => $css,
		];
	}

	/* ---------- POST /pages/{id}/restore ---------- */
	public static function restore( $req ) {
		$id      = (int) $req['id'];
		$body    = $req->get_json_params();
		$backups = get_post_meta( $id, self::META_BACKUPS, true );
		if ( ! is_array( $backups ) || ! $backups ) return new WP_Error( 'no_backups', 'Sin respaldos', [ 'status' => 404 ] );
		$key  = $body['backup'] ?? end( $backups )['key'];
		$found = null;
		foreach ( $backups as $b ) if ( $b['key'] === $key ) $found = $b;
		if ( ! $found ) return new WP_Error( 'bad_key', 'Respaldo no encontrado', [ 'status' => 404 ] );
		$area    = $found['area'] ?? 'content';
		$ckey    = self::key_for( $id, $area );
		$current = get_post_meta( $id, $ckey, true );
		self::backup( $id, is_array( $current ) ? $current : [], 'antes de restaurar ' . $key, $area );
		update_post_meta( $id, $ckey, wp_slash( $found['content'] ) );
		wp_update_post( [ 'ID' => $id ] );
		self::regenerate_css( $id, $found['content'], $area );
		return [ 'ok' => true, 'restored' => $key, 'area' => $area, 'elements' => count( $found['content'] ) ];
	}

	/* ---------- POST /pages ---------- */
	public static function create_page( $req ) {
		$body = $req->get_json_params();
		$title = sanitize_text_field( $body['title'] ?? '' );
		if ( ! $title ) return new WP_Error( 'no_title', 'Falta "title"', [ 'status' => 400 ] );
		$id = wp_insert_post( [
			'post_type'   => 'page',
			'post_title'  => $title,
			'post_name'   => sanitize_title( $body['slug'] ?? $title ),
			'post_status' => in_array( $body['status'] ?? 'draft', [ 'draft', 'publish', 'private' ], true ) ? $body['status'] : 'draft',
			'post_parent' => (int) ( $body['parent'] ?? 0 ),
		], true );
		if ( is_wp_error( $id ) ) return $id;
		update_post_meta( $id, self::META_MODE, 'bricks' );
		if ( ! empty( $body['content'] ) ) {
			$req2 = new WP_REST_Request( 'POST', '/' . self::NS . "/pages/$id/content" );
			$req2->set_param( 'id', $id );
			$req2->set_body( wp_json_encode( $body ) );
			$req2->set_header( 'Content-Type', 'application/json' );
			$r = self::set_content( $req2 );
			if ( is_wp_error( $r ) ) return $r;
			$r['created'] = true;
			return $r;
		}
		return [ 'ok' => true, 'id' => $id, 'created' => true, 'link' => get_permalink( $id ) ];
	}

	/* ---------- helpers ---------- */

	private static function validate( $elements ) {
		$ids = [];
		foreach ( $elements as $i => $e ) {
			if ( ! is_array( $e ) || empty( $e['id'] ) || empty( $e['name'] ) )
				return new WP_Error( 'bad_element', "Elemento #$i sin id o name", [ 'status' => 400 ] );
			if ( isset( $ids[ $e['id'] ] ) ) return new WP_Error( 'dup_id', "ID duplicado: {$e['id']}", [ 'status' => 400 ] );
			$ids[ $e['id'] ] = true;
		}
		foreach ( $elements as $e ) {
			$p = $e['parent'] ?? 0;
			if ( $p !== 0 && $p !== '0' && ! isset( $ids[ $p ] ) )
				return new WP_Error( 'orphan', "Elemento {$e['id']} apunta a un padre inexistente ($p)", [ 'status' => 400 ] );
			foreach ( $e['children'] ?? [] as $c )
				if ( ! isset( $ids[ $c ] ) ) return new WP_Error( 'bad_child', "Elemento {$e['id']} lista un hijo inexistente ($c)", [ 'status' => 400 ] );
		}
		return true;
	}

	/** IDs nuevos de 6 caracteres, como los genera Bricks, sin chocar con los existentes. */
	private static function regenerate_ids( $elements, $existing ) {
		$taken = [];
		foreach ( $existing as $e ) $taken[ $e['id'] ] = true;
		$map = [];
		foreach ( $elements as $e ) {
			do { $new = substr( str_shuffle( 'abcdefghijklmnopqrstuvwxyz0123456789' ), 0, 6 ); } while ( isset( $taken[ $new ] ) );
			$taken[ $new ] = true;
			$map[ $e['id'] ] = $new;
		}
		foreach ( $elements as &$e ) {
			$e['id'] = $map[ $e['id'] ];
			if ( isset( $e['parent'] ) && $e['parent'] !== 0 && $e['parent'] !== '0' ) $e['parent'] = $map[ $e['parent'] ] ?? $e['parent'];
			if ( ! empty( $e['children'] ) ) $e['children'] = array_map( function ( $c ) use ( $map ) { return $map[ $c ] ?? $c; }, $e['children'] );
		}
		return $elements;
	}

	/** Añade a bricks_global_classes las clases que no existan (por id). */
	private static function merge_global_classes( $classes ) {
		if ( ! $classes ) return 0;
		$opt = get_option( self::OPT_CLASSES, [] );
		if ( ! is_array( $opt ) ) $opt = [];
		$have = [];
		foreach ( $opt as $c ) if ( isset( $c['id'] ) ) $have[ $c['id'] ] = true;
		$added = 0;
		foreach ( $classes as $c ) {
			if ( empty( $c['id'] ) || isset( $have[ $c['id'] ] ) ) continue;
			$opt[] = $c; $added++;
		}
		if ( $added ) update_option( self::OPT_CLASSES, $opt );
		return $added;
	}

	private static function backup( $id, $content, $nota, $area = 'content' ) {
		$backups = get_post_meta( $id, self::META_BACKUPS, true );
		if ( ! is_array( $backups ) ) $backups = [];
		$key = gmdate( 'Ymd-His' );
		$backups[] = [ 'key' => $key, 'date' => current_time( 'mysql' ), 'nota' => $nota, 'area' => $area, 'content' => $content ];
		$backups = array_slice( $backups, - self::MAX_BACKUPS );
		update_post_meta( $id, self::META_BACKUPS, wp_slash( $backups ) );
		return $key;
	}

	/**
	 * Si Bricks sirve el CSS como archivos externos, regenera el de esta página.
	 * En contexto REST Bricks no tiene cargados sus elementos y puede generar un CSS
	 * vacío (lo que borra el archivo y deja la página sin estilos). Por eso: se cargan
	 * los elementos antes, se verifica el resultado y, si queda vacío, se restaura el
	 * archivo anterior. Devuelve un pequeño informe.
	 */
	private static function regenerate_css( $id, $elements, $area = 'content' ) {
		$info = [ 'mode' => 'inline', 'status' => 'skipped' ];
		if ( ! class_exists( '\Bricks\Assets_Files' ) || ! method_exists( '\Bricks\Assets_Files', 'generate_post_css_file' ) ) return $info;

		$dir  = trailingslashit( wp_upload_dir()['basedir'] ) . 'bricks/css/';
		$file = $dir . 'post-' . $id . '.min.css';
		$prev = file_exists( $file ) ? (string) file_get_contents( $file ) : null;
		$info = [ 'mode' => 'file', 'file' => basename( $file ), 'before' => $prev === null ? null : strlen( $prev ) ];

		// Elementos que llevan estilos propios (ajustes "_..."): su CSS debe aparecer en el archivo.
		$styled = 0;
		foreach ( $elements as $e ) {
			$st = isset( $e['settings'] ) && is_array( $e['settings'] ) ? $e['settings'] : [];
			foreach ( $st as $k => $v ) if ( is_string( $k ) && $k !== '' && $k[0] === '_' ) { $styled++; break; }
		}
		$info['styled_elements'] = $styled;

		// Cargar elementos de Bricks (fuera del builder/frontend no están registrados).
		try {
			if ( class_exists( '\Bricks\Elements' ) ) {
				if ( method_exists( '\Bricks\Elements', 'init_elements' ) ) \Bricks\Elements::init_elements();
				elseif ( method_exists( '\Bricks\Elements', 'register_elements' ) ) \Bricks\Elements::register_elements();
			}
		} catch ( \Throwable $t ) { $info['init_error'] = $t->getMessage(); }

		try { \Bricks\Assets_Files::generate_post_css_file( $id, $area, $elements ); }
		catch ( \Throwable $t ) { $info['gen_error'] = $t->getMessage(); }

		clearstatcache();
		$new = file_exists( $file ) ? (string) file_get_contents( $file ) : null;
		$info['after'] = $new === null ? null : strlen( $new );
		$rules = $new === null ? 0 : preg_match_all( '/\.brxe-[a-z0-9]{6}\b/', $new );
		$info['rules'] = $rules;

		$empty = $styled > 0 && $rules === 0;
		if ( $empty && $prev !== null && $prev !== '' ) {
			if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
			file_put_contents( $file, $prev );
			$info['status'] = 'empty_restored_previous';
		} elseif ( $empty ) {
			$info['status'] = 'empty_no_previous';
		} else {
			$info['status'] = 'ok';
		}
		if ( function_exists( 'wp_cache_flush' ) ) wp_cache_flush();
		return $info;
	}
}
Lucuma_Bricks_Bridge::init();
