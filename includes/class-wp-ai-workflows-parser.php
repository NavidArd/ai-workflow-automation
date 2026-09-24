<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


class WP_AI_Workflows_Parser {
	public static function parse_document_with_llamaparse( $document_url, $parser_settings ) {
		$api_key = self::get_llamaparse_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'llamaparse_api_key_missing', 'LlamaParse API key is not set' );
		}

		$api_url = 'https://api.cloud.llamaindex.ai/api/parsing/upload';

		$file_path = str_replace( site_url( '/' ), ABSPATH, $document_url );
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_not_found', 'The specified file does not exist' );
		}

		$boundary = wp_generate_password( 24 );
		$payload  = '';

		$payload .= '--' . $boundary . "\r\n";
		$payload .= 'Content-Disposition: form-data; name="file"; filename="' . basename( $file_path ) . '"' . "\r\n";
		$payload .= "Content-Type: application/octet-stream\r\n\r\n";
		$payload .= file_get_contents( $file_path ) . "\r\n";

		$fields = array(
			'language'              => $parser_settings['language'],
			'parsing_instruction'   => $parser_settings['parsingInstructions'],
			'skip_diagonal_text'    => $parser_settings['skipDiagonalText'] ? 'true' : 'false',
			'do_not_unroll_columns' => $parser_settings['doNotUnrollColumns'] ? 'true' : 'false',
			'target_pages'          => $parser_settings['targetPages'],
		);

		foreach ( $fields as $name => $value ) {
			$payload .= '--' . $boundary . "\r\n";
			$payload .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
			$payload .= $value . "\r\n";
		}

		$payload .= '--' . $boundary . "--\r\n";

		$args = array(
			'body'    => $payload,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
			),
			'timeout' => 60,
		);

		$response = wp_remote_post( $api_url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data['id'] ) ) {
			return self::poll_llamaparse_results( $data['id'] );
		} else {
			return new WP_Error( 'parsing_error', 'Failed to start parsing job: ' . ( isset( $data['detail'] ) ? $data['detail'] : 'Unknown error' ) );
		}
	}

	private static function poll_llamaparse_results( $job_id ) {
		$api_key = self::get_llamaparse_api_key();
		$api_url = "https://api.cloud.llamaindex.ai/api/parsing/job/{$job_id}";

		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
			),
			'timeout' => 60,
		);

		$max_attempts = 20;
		$attempt      = 0;

		while ( $attempt < $max_attempts ) {
			$response = wp_remote_get( $api_url, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( ! isset( $data['status'] ) ) {
				return new WP_Error( 'invalid_response', 'Invalid response from LlamaParse API' );
			}

			switch ( $data['status'] ) {
				case 'SUCCESS':
				case 'PARTIAL_SUCCESS':
					return self::fetch_llamaparse_result( $job_id );
				case 'ERROR':
					$error_message = isset( $data['detail'] ) ? $data['detail'] : 'Unknown error';
					return new WP_Error( 'parsing_error', 'Parsing job failed: ' . $error_message );
				case 'PENDING':
					break;
				default:
					return new WP_Error( 'unknown_status', 'Unknown status received from LlamaParse API' );
			}

			++$attempt;
			sleep( 5 );
		}

		return new WP_Error( 'polling_timeout', 'Timed out waiting for parsing results' );
	}

	private static function fetch_llamaparse_result( $job_id ) {
		$api_key = self::get_llamaparse_api_key();
		$api_url = "https://api.cloud.llamaindex.ai/api/parsing/job/{$job_id}/result/markdown";

		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
			),
			'timeout' => 60,
		);

		$response = wp_remote_get( $api_url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data['markdown'] ) ) {
			return $data['markdown'];
		} else {
			return new WP_Error( 'result_fetch_error', 'Failed to fetch parsing result' );
		}
	}

	private static function get_llamaparse_api_key() {
		return WP_AI_Workflows_Utilities::get_llamaparse_api_key();
	}
}
