<?php
/**
 * Plugin Name: Lucuma Bricks Bridge
 * Description: Endpoint REST para leer y escribir el contenido Bricks de una página desde fuera (importa JSON en formato bricksCopiedElements). Con respaldo automático y restauración.
 * Version: 1.0.0
 * Author: Lucuma Agency
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Lucuma_Bricks_Bridge {

	const NS            = 'lucuma-bricks/v1';
	const META_CONTENT  = '_bricks_page_content_2';
	const META_MODE     = '_bricks_editor_mode';
	const META_BACKUPS  = '_lucuma_bricks_backups';
	const OPT_CLASSES   = 'bricks_global_classes';
	const MAX_BACKUPS   = 5;

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes() {
		$perm = function ( $req ) {
			$id = (int) $req->get_param( 'id' );
			return $id ? current_user_can( 'edit_post', $id ) : current_user_can( 'edit_pages' );
		};
		register_rest_route( self::NS, '/health', [
			'methods' => 'GET', 'permission_callback' => '__return_true',
			'callback' => function () {
				return [ 'ok' => true, 'bricks' => defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : null, 'plugin' => '1.0.0' ];
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
		register_rest_route( self::NS, '/pages', [
			'methods' => 'POST', 'permission_callback' => $perm, 'callback' => [ __CLASS__, 'create_page' ],
		] );
		register_rest_route( self::NS, '/classes', [
			'methods' => 'GET', 'permission_callback' => $perm,
			'callback' => function () { return get_option( self::OPT_CLASSES, [] ); },
		] );
	}

	/* ---------- GET /pages/{id} ---------- */
	public static function get_page( $req ) {
		$id   = (int) $req['id'];
		$post = get_post( $id );
		if ( ! $post ) return new WP_Error( 'not_found', 'Página no encontrada', [ 'status' => 404 ] );
		$content = get_post_meta( $id, self::META_CONTENT, true );
		$backups = get_post_meta( $id, self::META_BACKUPS, true );
		return [
			'id'       => $id,
			'title'    => $post->post_title,
			'slug'     => $post->post_name,
			'status'   => $post->post_status,
			'link'     => get_permalink( $id ),
			'mode'     => get_post_meta( $id, self::META_MODE, true ),
			'elements' => is_array( $content ) ? count( $content ) : 0,
			'content'  => is_array( $content ) ? $content : [],
			'backups'  => is_array( $backups ) ? array_map( function ( $b ) { return [ 'key' => $b['key'], 'date' => $b['date'], 'elements' => count( $b['content'] ), 'nota' => $b['nota'] ]; }, $backups ) : [],
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

		$current = get_post_meta( $id, self::META_CONTENT, true );
		$current = is_array( $current ) ? $current : [];

		if ( $regen ) $elements = self::regenerate_ids( $elements, $current );
		$classes_added = self::merge_global_classes( $classes );

		$new = $mode === 'append' ? array_merge( $current, $elements ) : $elements;

		$backup_key = self::backup( $id, $current, $nota ?: 'antes de ' . $mode );

		update_post_meta( $id, self::META_CONTENT, wp_slash( $new ) );
		update_post_meta( $id, self::META_MODE, 'bricks' );
		self::regenerate_css( $id, $new );
		wp_update_post( [ 'ID' => $id ] ); // toca modified y limpia cachés de objeto

		return [
			'ok'            => true,
			'id'            => $id,
			'link'          => get_permalink( $id ),
			'mode'          => $mode,
			'elements'      => count( $new ),
			'roots'         => count( array_filter( $new, function ( $e ) { return ( $e['parent'] ?? 0 ) === 0 || $e['parent'] === '0'; } ) ),
			'ids_regenerated' => $regen,
			'classes_added' => $classes_added,
			'backup'        => $backup_key,
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
		$current = get_post_meta( $id, self::META_CONTENT, true );
		self::backup( $id, is_array( $current ) ? $current : [], 'antes de restaurar ' . $key );
		update_post_meta( $id, self::META_CONTENT, wp_slash( $found['content'] ) );
		self::regenerate_css( $id, $found['content'] );
		wp_update_post( [ 'ID' => $id ] );
		return [ 'ok' => true, 'restored' => $key, 'elements' => count( $found['content'] ) ];
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

	private static function backup( $id, $content, $nota ) {
		$backups = get_post_meta( $id, self::META_BACKUPS, true );
		if ( ! is_array( $backups ) ) $backups = [];
		$key = gmdate( 'Ymd-His' );
		$backups[] = [ 'key' => $key, 'date' => current_time( 'mysql' ), 'nota' => $nota, 'content' => $content ];
		$backups = array_slice( $backups, - self::MAX_BACKUPS );
		update_post_meta( $id, self::META_BACKUPS, wp_slash( $backups ) );
		return $key;
	}

	/** Si Bricks sirve el CSS como archivos externos, regenera el de esta página. */
	private static function regenerate_css( $id, $elements ) {
		if ( class_exists( '\Bricks\Assets_Files' ) && method_exists( '\Bricks\Assets_Files', 'generate_post_css_file' ) ) {
			try { \Bricks\Assets_Files::generate_post_css_file( $id, 'content', $elements ); } catch ( \Throwable $t ) {}
		}
		if ( function_exists( 'wp_cache_flush' ) ) wp_cache_flush();
	}
}
Lucuma_Bricks_Bridge::init();
