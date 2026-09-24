<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multimedia Generator Class
 *
 * Handles integration with Fal.ai services for generating images and videos
 *
 * @package WP_AI_Workflows
 */

class WP_AI_Workflows_Multimedia_Generator {

	/**
	 * Base API URL for Fal.ai
	 */
	const API_BASE_URL = 'https://queue.fal.run/';

	/**
	 * Default timeout for API requests in seconds
	 */
	const DEFAULT_TIMEOUT = 600;

	/**
	 * Extended timeout for video processing in seconds
	 */
	const VIDEO_TIMEOUT = 600;

	/**
	 * Available text-to-image models
	 */
	private $text_to_image_models = array(
		'fal-ai/hidream-i1-fast' => array(
			'name'           => 'HiDream I1 Fast',
			'cost_per_image' => 0.05,
		),
		'fal-ai/ideogram/v2'     => array(
			'name'           => 'Ideogram V2',
			'cost_per_image' => 0.08,
		),
		'fal-ai/flux/dev'        => array(
			'name'           => 'Flux.1 DEV',
			'cost_per_image' => 0.01,
		),
		'fal-ai/sana/sprint'     => array(
			'name'           => 'Sana Sprint',
			'cost_per_image' => 0.01,
		),
	);

	/**
	 * Available image-to-video models
	 */
	private $image_to_video_models = array(
		'fal-ai/veo2/image-to-video'                  => array(
			'name'            => 'Veo 2',
			'base_cost'       => 2.50, // 5-second video
			'cost_per_second' => 0.50,
		),
		'fal-ai/kling-video/v2/master/image-to-video' => array(
			'name'      => 'Kling V2 Master',
			'flat_cost' => 1.50, //5 seconds
		),
	);

	/**
	 * Available text-to-video models
	 */
	private $text_to_video_models = array(
		'fal-ai/kling-video/v2/master/text-to-video' => array(
			'name'      => 'Kling V2 Master',
			'flat_cost' => 1.40, //5 seconds
		),
		'fal-ai/veo2'                                => array(
			'name'            => 'Veo 2',
			'base_cost'       => 2.50, // 5-second video
			'cost_per_second' => 0.50,
		),
	);

	/**
	 * Initialize the class
	 */
	public function init() {
		// This class does not register endpoints directly
		// Endpoints will be registered in the REST API class
	}

	/**
	 * Get available models by type
	 *
	 * @param string $type Model type (text_to_image, image_to_video, text_to_video)
	 * @return array List of models
	 */
	public function get_available_models( $type = 'all' ) {
		switch ( $type ) {
			case 'text_to_image':
				return $this->text_to_image_models;
			case 'image_to_video':
				return $this->image_to_video_models;
			case 'text_to_video':
				return $this->text_to_video_models;
			case 'all':
			default:
				return array_merge(
					$this->text_to_image_models,
					$this->image_to_video_models,
					$this->text_to_video_models
				);
		}
	}


	/**
	 * Generate an image from text prompt
	 *
	 * @param array $params Parameters for image generation
	 * @return array|WP_Error Result of the API call
	 */
	public function generate_image( $params ) {
		if ( empty( $params['model'] ) ) {
			return new WP_Error( 'missing_model', 'Model is required' );
		}

		if ( empty( $params['prompt'] ) ) {
			return new WP_Error( 'missing_prompt', 'Prompt is required' );
		}

		$api_key = WP_AI_Workflows_Utilities::get_fal_ai_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_api_key', 'Fal.ai API key is required' );
		}

		$model  = sanitize_text_field( $params['model'] );
		$prompt = sanitize_text_field( $params['prompt'] );

		$request_body = array(
			'prompt' => $prompt,
		);

		$request_body = $this->add_model_specific_params_for_image( $model, $request_body, $params );

		$api_endpoint = self::API_BASE_URL . $model;

		WP_AI_Workflows_Utilities::debug_log(
			'Fal.ai image generation request',
			'debug',
			array(
				'endpoint'       => $api_endpoint,
				'model'          => $model,
				'headers'        => array(
					'Authorization' => 'Key (masked)',
					'Content-Type'  => 'application/json',
				),
				'request_body'   => $request_body,
				'api_key_length' => strlen( $api_key ),
			)
		);

		$response = wp_remote_post(
			$api_endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Key ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
				'timeout' => self::DEFAULT_TIMEOUT,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Fal.ai API error response',
				'error',
				array(
					'error' => $response->get_error_message(),
				)
			);
		} else {
			WP_AI_Workflows_Utilities::debug_log(
				'Fal.ai API raw response',
				'debug',
				array(
					'status_code'      => wp_remote_retrieve_response_code( $response ),
					'response_headers' => wp_remote_retrieve_headers( $response ),
					'response_body'    => substr( wp_remote_retrieve_body( $response ), 0, 500 ) . '...',
				)
			);
		}

		return $this->process_api_response( $response, 'image', $api_key, $model, $prompt );
	}

	/**
	 * Add model-specific parameters for image generation
	 *
	 * @param string $model Model ID
	 * @param array $request_body Base request body with prompt
	 * @param array $params All parameters passed to generate_image
	 * @return array Updated request body
	 */
	private function add_model_specific_params_for_image( $model, $request_body, $params ) {
		// Negative prompt is common to multiple models.
		if ( ! empty( $params['negative_prompt'] ) ) {
			$request_body['negative_prompt'] = sanitize_text_field( $params['negative_prompt'] );
		}

		if ( strpos( $model, 'hidream' ) !== false ) {
			// HiDream model parameters
			if ( isset( $params['image_size'] ) && is_array( $params['image_size'] ) ) {
				if ( isset( $params['image_size']['width'] ) && isset( $params['image_size']['height'] ) ) {
					$request_body['image_size'] = array(
						'width'  => intval( $params['image_size']['width'] ),
						'height' => intval( $params['image_size']['height'] ),
					);
				}
			}

			if ( isset( $params['num_inference_steps'] ) ) {
				$request_body['num_inference_steps'] = intval( $params['num_inference_steps'] );
			}

			if ( isset( $params['seed'] ) && $params['seed'] !== -1 ) {
				$request_body['seed'] = intval( $params['seed'] );
			}

			if ( isset( $params['num_images'] ) ) {
				$request_body['num_images'] = intval( $params['num_images'] );
			}

			if ( isset( $params['output_format'] ) ) {
				$request_body['output_format'] = sanitize_text_field( $params['output_format'] );
			}
		} elseif ( strpos( $model, 'ideogram' ) !== false ) {
			// Ideogram model parameters
			// Map image size to aspect ratio if provided
			if ( isset( $params['image_size'] ) && is_array( $params['image_size'] ) ) {
				$width  = intval( $params['image_size']['width'] );
				$height = intval( $params['image_size']['height'] );

				if ( $width > $height ) {
					$request_body['aspect_ratio'] = '16:9';
				} elseif ( $height > $width ) {
					$request_body['aspect_ratio'] = '9:16';
				} else {
					$request_body['aspect_ratio'] = '1:1';
				}
			} else {
				$request_body['aspect_ratio'] = '1:1'; // Default
			}

			if ( isset( $params['seed'] ) && $params['seed'] !== -1 ) {
				$request_body['seed'] = intval( $params['seed'] );
			}

			// Ideogram V2 specific parameters
			if ( strpos( $model, 'v2' ) !== false ) {
				$request_body['expand_prompt'] = true; // Default value

				if ( isset( $params['style'] ) ) {
					$request_body['style'] = sanitize_text_field( $params['style'] );
				} else {
					$request_body['style'] = 'auto'; // Default
				}
			}
		} elseif ( strpos( $model, 'flux' ) !== false ) {
			// Flux model parameters
			if ( isset( $params['num_inference_steps'] ) ) {
				$request_body['num_inference_steps'] = intval( $params['num_inference_steps'] );
			}

			if ( isset( $params['seed'] ) && $params['seed'] !== -1 ) {
				$request_body['seed'] = intval( $params['seed'] );
			}

			if ( isset( $params['num_images'] ) ) {
				$request_body['num_images'] = intval( $params['num_images'] );
			}

			if ( isset( $params['guidance_scale'] ) ) {
				$request_body['guidance_scale'] = floatval( $params['guidance_scale'] );
			} else {
				$request_body['guidance_scale'] = 3.5; // Default
			}

			if ( isset( $params['image_size'] ) && is_array( $params['image_size'] ) ) {
				$width  = intval( $params['image_size']['width'] );
				$height = intval( $params['image_size']['height'] );

				// Flux uses specific aspect ratio enum values
				if ( $width > $height ) {
					$request_body['image_size'] = 'landscape_16_9';
				} elseif ( $height > $width ) {
					$request_body['image_size'] = 'portrait_16_9';
				} else {
					$request_body['image_size'] = 'square';
				}
			} else {
				$request_body['image_size'] = 'landscape_4_3'; // Default
			}
		} elseif ( strpos( $model, 'sana' ) !== false ) {
			// Sana model parameters
			if ( isset( $params['num_inference_steps'] ) ) {
				$request_body['num_inference_steps'] = intval( $params['num_inference_steps'] );
			}

			if ( isset( $params['seed'] ) && $params['seed'] !== -1 ) {
				$request_body['seed'] = intval( $params['seed'] );
			}

			if ( isset( $params['num_images'] ) ) {
				$request_body['num_images'] = intval( $params['num_images'] );
			}

			if ( isset( $params['guidance_scale'] ) ) {
				$request_body['guidance_scale'] = floatval( $params['guidance_scale'] );
			} else {
				$request_body['guidance_scale'] = 5.0; // Default for Sana
			}

			if ( isset( $params['style_name'] ) ) {
				$request_body['style_name'] = sanitize_text_field( $params['style_name'] );
			} else {
				$request_body['style_name'] = '(No style)'; // Default
			}

			if ( isset( $params['image_size'] ) && is_array( $params['image_size'] ) ) {
				$request_body['image_size'] = array(
					'width'  => intval( $params['image_size']['width'] ),
					'height' => intval( $params['image_size']['height'] ),
				);
			} else {
				// Sana default is high-res landscape
				$request_body['image_size'] = array(
					'width'  => 3840,
					'height' => 2160,
				);
			}
		}

		return $request_body;
	}

	/**
	 * Generate a video from image or text
	 *
	 * @param array $params Parameters for video generation
	 * @return array|WP_Error Result of the API call
	 */
	public function generate_video( $params ) {
		if ( empty( $params['model'] ) ) {
			return new WP_Error( 'missing_model', 'Model is required' );
		}

		$api_key = WP_AI_Workflows_Utilities::get_fal_ai_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_api_key', 'Fal.ai API key is required' );
		}

		$model     = sanitize_text_field( $params['model'] );
		$prompt    = isset( $params['prompt'] ) ? sanitize_text_field( $params['prompt'] ) : '';
		$image_url = isset( $params['image_url'] ) ? esc_url_raw( $params['image_url'] ) : '';

		$is_image_to_video = isset( $this->image_to_video_models[ $model ] );
		$is_text_to_video  = isset( $this->text_to_video_models[ $model ] );

		if ( ! $is_image_to_video && ! $is_text_to_video ) {
			return new WP_Error( 'invalid_model', 'The requested model is not supported' );
		}

		if ( $is_image_to_video && empty( $image_url ) ) {
			return new WP_Error( 'missing_image', 'Image URL is required for image-to-video models' );
		}

		if ( $is_text_to_video && empty( $prompt ) ) {
			return new WP_Error( 'missing_prompt', 'Prompt is required for text-to-video models' );
		}

		$request_body = array();

		if ( $is_image_to_video ) {
			$request_body['image_url'] = $image_url;
			if ( ! empty( $prompt ) ) {
				$request_body['prompt'] = $prompt;
			}
		} elseif ( $is_text_to_video ) {
			$request_body['prompt'] = $prompt;
		}

		$request_body = $this->add_model_specific_params_for_video( $model, $request_body, $params );

		$api_endpoint = self::API_BASE_URL . $model;

		WP_AI_Workflows_Utilities::debug_log(
			'Fal.ai video generation request',
			'debug',
			array(
				'endpoint'       => $api_endpoint,
				'model'          => $model,
				'headers'        => array(
					'Authorization' => 'Key (masked)',
					'Content-Type'  => 'application/json',
				),
				'request_body'   => $request_body,
				'api_key_length' => strlen( $api_key ),
			)
		);

		$response = wp_remote_post(
			$api_endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Key ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
				'timeout' => self::VIDEO_TIMEOUT,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Fal.ai API error response',
				'error',
				array(
					'error' => $response->get_error_message(),
				)
			);
		} else {
			WP_AI_Workflows_Utilities::debug_log(
				'Fal.ai API raw response',
				'debug',
				array(
					'status_code'      => wp_remote_retrieve_response_code( $response ),
					'response_headers' => wp_remote_retrieve_headers( $response ),
					'response_body'    => substr( wp_remote_retrieve_body( $response ), 0, 500 ) . '...',
				)
			);
		}

		return $this->process_api_response( $response, 'video', $api_key, $model, $prompt );
	}

	/**
	 * Add model-specific parameters for video generation
	 *
	 * @param string $model Model ID
	 * @param array $request_body Base request body
	 * @param array $params All parameters passed to generate_video
	 * @return array Updated request body
	 */
	private function add_model_specific_params_for_video( $model, $request_body, $params ) {
		$video_length = isset( $params['video_length'] ) ? intval( $params['video_length'] ) : 5;

		// Process Veo2 models
		if ( strpos( $model, 'veo2' ) !== false ) {
			// Convert video length to Veo2 duration format
			if ( $video_length >= 5 && $video_length <= 8 ) {
				$request_body['duration'] = $video_length . 's';
			} else {
				// Default to 5s if outside valid range
				$request_body['duration'] = '5s';
			}

			if ( isset( $params['aspect_ratio'] ) ) {
				$request_body['aspect_ratio'] = sanitize_text_field( $params['aspect_ratio'] );
			} else {
				// Default to 16:9 for Veo2
				$request_body['aspect_ratio'] = '16:9';
			}
		}
		// Process Kling models
		elseif ( strpos( $model, 'kling-video' ) !== false ) {
			// Kling uses numeric duration values
			if ( $video_length >= 5 && $video_length <= 10 ) {
				$request_body['duration'] = strval( $video_length );
			} else {
				// Default to 5 if outside valid range
				$request_body['duration'] = '5';
			}

			if ( isset( $params['aspect_ratio'] ) ) {
				$request_body['aspect_ratio'] = sanitize_text_field( $params['aspect_ratio'] );
			} else {
				// Default to 16:9 for Kling
				$request_body['aspect_ratio'] = '16:9';
			}

			if ( isset( $params['negative_prompt'] ) ) {
				$request_body['negative_prompt'] = sanitize_text_field( $params['negative_prompt'] );
			} else {
				// Kling has a default negative prompt
				$request_body['negative_prompt'] = 'blur, distort, and low quality';
			}
		}

		return $request_body;
	}

	/**
	 * Handle file upload for image-to-video processing
	 *
	 * @param array $file File data from $_FILES
	 * @return array|WP_Error Upload result with URL or error
	 */
	public function upload_file( $file ) {
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( empty( $file ) ) {
			return new WP_Error( 'missing_file', 'No file was uploaded' );
		}

		$upload_overrides = array(
			'test_form'   => false,
			'test_size'   => true,
			'test_upload' => true,
		);

		$uploaded_file = wp_handle_upload( $file, $upload_overrides );

		if ( isset( $uploaded_file['error'] ) ) {
			return new WP_Error( 'upload_error', $uploaded_file['error'] );
		}

		if ( ! isset( $uploaded_file['url'] ) ) {
			return new WP_Error( 'upload_error', 'Failed to upload file' );
		}

		return array(
			'url'  => $uploaded_file['url'],
			'file' => $uploaded_file['file'],
		);
	}

	/**
	 * Estimate cost for a multimedia generation request
	 *
	 * @param string $model_id The model ID
	 * @param string $generation_type Type of generation (image, image_to_video, text_to_video)
	 * @param int $quantity Number of images or video length
	 * @return float|null Estimated cost or null if model not found
	 */
	public function estimate_cost( $model_id, $generation_type, $quantity = 1 ) {
		// Check text-to-image models
		if ( isset( $this->text_to_image_models[ $model_id ] ) ) {
			return $this->text_to_image_models[ $model_id ]['cost_per_image'] * $quantity;
		}

		// Check image-to-video models
		if ( isset( $this->image_to_video_models[ $model_id ] ) ) {
			$model = $this->image_to_video_models[ $model_id ];

			// If it has a flat cost, return that
			if ( isset( $model['flat_cost'] ) ) {
				return $model['flat_cost'];
			}

			// If it has a base cost and per-second cost (like Veo 2)
			if ( isset( $model['base_cost'] ) && isset( $model['cost_per_second'] ) ) {
				$seconds            = max( 5, $quantity ); // Minimum 5 seconds for Veo 2
				$additional_seconds = $seconds - 5;
				return $model['base_cost'] + ( $additional_seconds * $model['cost_per_second'] );
			}
		}

		// Check text-to-video models
		if ( isset( $this->text_to_video_models[ $model_id ] ) ) {
			$model = $this->text_to_video_models[ $model_id ];

			// If it has a flat cost, return that
			if ( isset( $model['flat_cost'] ) ) {
				return $model['flat_cost'];
			}

			// If it has a base cost and per-second cost (like Veo 2)
			if ( isset( $model['base_cost'] ) && isset( $model['cost_per_second'] ) ) {
				$seconds            = max( 5, $quantity ); // Minimum 5 seconds for Veo 2
				$additional_seconds = $seconds - 5;
				return $model['base_cost'] + ( $additional_seconds * $model['cost_per_second'] );
			}
		}

		return null;
	}


	/**
	 * Prepare payload for video generation based on the selected model
	 *
	 * @param string $model Model ID
	 * @param string $prompt Text prompt
	 * @param string $image_url Image URL for image-to-video models
	 * @param int $video_length Video length in seconds
	 * @return array Request payload for the API
	 */
	private function prepare_video_generation_payload( $model, $prompt, $image_url, $video_length ) {
		$request_body = array(
			'input' => array(),
		);

		// Image-to-video models
		if ( isset( $this->image_to_video_models[ $model ] ) ) {
			$request_body['input']['image_url'] = $image_url;

			if ( ! empty( $prompt ) && in_array( $model, array( 'fal-ai/veo2/image-to-video' ) ) ) {
				$request_body['input']['prompt'] = $prompt;
			}

			if ( in_array( $model, array( 'fal-ai/veo2/image-to-video' ) ) ) {
				// Veo2 doesn't explicitly take a length param, but bills by length;
				// future-proofing this branch in case that changes.
			}
		}
		// Text-to-video models
		elseif ( isset( $this->text_to_video_models[ $model ] ) ) {
			$request_body['input']['prompt'] = $prompt;

			if ( $model === 'fal-ai/fast-video' ) {
				$request_body['input']['seconds'] = $video_length;
			}
		}

		return $request_body;
	}

	/**
	 * Process the API response and handle queue-based workflows
	 *
	 * @param array|WP_Error $response Response from wp_remote_post
	 * @param string $type Response type (image or video)
	 * @param string $api_key Fal.ai API key for status checks
	 * @param string $model_id Model ID used for the request
	 * @return array|WP_Error Processed response or error
	 */
	private function process_api_response( $response, $type = 'image', $api_key = '', $model_id = '', $prompt = '' ) {
		if ( is_wp_error( $response ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'API error',
				'error',
				array(
					'error' => $response->get_error_message(),
				)
			);
			return new WP_Error( 'api_error', $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		$response_body = json_decode( $body, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Invalid JSON response',
				'error',
				array(
					'status_code'   => $status_code,
					'response_body' => $body,
				)
			);
			return new WP_Error( 'invalid_response', 'Invalid response from API' );
		}

		if ( $status_code !== 200 ) {
			$error_message = isset( $response_body['error'] ) ?
				$response_body['error'] :
				'Unknown error (HTTP ' . $status_code . ')';

			WP_AI_Workflows_Utilities::debug_log(
				'API returned non-200 status',
				'error',
				array(
					'status_code'   => $status_code,
					'error_message' => $error_message,
				)
			);

			return new WP_Error( 'api_error', $error_message );
		}

		// Queue-based workflow: request_id needs polling.
		if ( isset( $response_body['request_id'] ) && ! empty( $api_key ) && ! empty( $model_id ) ) {
			$request_id = $response_body['request_id'];

			WP_AI_Workflows_Utilities::debug_log(
				'Received request_id, starting queue polling',
				'info',
				array(
					'request_id' => $request_id,
					'model_id'   => $model_id,
				)
			);

			$result = $this->poll_queue_until_complete( $request_id, $model_id, $api_key, $prompt );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $result;
		}

		// No request_id: this is a direct (non-queued) response.
		WP_AI_Workflows_Utilities::debug_log(
			'Generation successful',
			'info',
			array(
				'type'             => $type,
				'status_code'      => $status_code,
				'response_summary' => $this->get_response_summary( $response_body, $type ),
			)
		);

		return $response_body;
	}

	/**
	 * Poll the queue until the job is complete
	 *
	 * @param string $request_id Request ID from the queue submission
	 * @param string $model_id Model ID being used
	 * @param string $api_key Fal.ai API key
	 * @param int $max_attempts Maximum number of polling attempts (default: 30)
	 * @param int $initial_backoff Initial backoff time in seconds (default: 1)
	 * @return array|WP_Error The final result or error
	 */
	private function poll_queue_until_complete( $request_id, $model_id, $api_key, $prompt = '', $max_attempts = 30, $initial_backoff = 1 ) {
		$attempts = 0;
		$backoff  = $initial_backoff;

		$base_path = $this->get_base_path_for_model( $model_id );

		WP_AI_Workflows_Utilities::debug_log(
			'Received request_id, starting queue polling',
			'info',
			array(
				'request_id' => $request_id,
				'model_id'   => $model_id,
				'base_path'  => $base_path,
			)
		);

		while ( $attempts < $max_attempts ) {
			if ( $attempts > 0 ) {
				sleep( $backoff );
				$backoff = min( $backoff * 1.5, 10 );
			}

			++$attempts;

			$status_url = self::API_BASE_URL . $base_path . '/requests/' . $request_id . '/status';

			WP_AI_Workflows_Utilities::debug_log(
				'Checking job status',
				'debug',
				array(
					'attempt'    => $attempts,
					'status_url' => $status_url,
				)
			);

			$status_response = wp_remote_get(
				$status_url,
				array(
					'headers' => array(
						'Authorization' => 'Key ' . $api_key,
					),
					'timeout' => 15,
				)
			);

			if ( is_wp_error( $status_response ) ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Error checking queue status',
					'error',
					array(
						'attempt' => $attempts,
						'error'   => $status_response->get_error_message(),
					)
				);
				continue;
			}

			$status_code     = wp_remote_retrieve_response_code( $status_response );
			$status_body_raw = wp_remote_retrieve_body( $status_response );

			WP_AI_Workflows_Utilities::debug_log(
				'Status check response',
				'debug',
				array(
					'attempt'      => $attempts,
					'status_code'  => $status_code,
					'raw_response' => substr( $status_body_raw, 0, 500 ),
				)
			);

			if ( $status_code !== 200 ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Invalid status response',
					'warning',
					array(
						'attempt'     => $attempts,
						'status_code' => $status_code,
						'body'        => $status_body_raw,
					)
				);
				continue;
			}

			$status_body = json_decode( $status_body_raw, true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $status_body['status'] ) ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Invalid status response',
					'warning',
					array(
						'attempt'    => $attempts,
						'json_error' => json_last_error_msg(),
						'body'       => $status_body_raw,
					)
				);
				continue;
			}

			WP_AI_Workflows_Utilities::debug_log(
				'Job status update',
				'debug',
				array(
					'request_id' => $request_id,
					'status'     => $status_body['status'],
					'attempt'    => $attempts,
				)
			);

			if ( $status_body['status'] === 'COMPLETED' ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Job completed, fetching result',
					'info',
					array(
						'request_id' => $request_id,
					)
				);

				$result_url = self::API_BASE_URL . $base_path . '/requests/' . $request_id;

				WP_AI_Workflows_Utilities::debug_log(
					'Fetching result',
					'debug',
					array(
						'result_url' => $result_url,
					)
				);

				$result_response = wp_remote_get(
					$result_url,
					array(
						'headers' => array(
							'Authorization' => 'Key ' . $api_key,
						),
						'timeout' => 300,
					)
				);

				if ( is_wp_error( $result_response ) ) {
					WP_AI_Workflows_Utilities::debug_log(
						'Error fetching result',
						'error',
						array(
							'error' => $result_response->get_error_message(),
						)
					);
					return new WP_Error( 'result_fetch_error', 'Error fetching result: ' . $result_response->get_error_message() );
				}

				$result_code     = wp_remote_retrieve_response_code( $result_response );
				$result_body_raw = wp_remote_retrieve_body( $result_response );

				WP_AI_Workflows_Utilities::debug_log(
					'Result response',
					'debug',
					array(
						'status_code'  => $result_code,
						'raw_response' => substr( $result_body_raw, 0, 500 ),
					)
				);

				if ( $result_code !== 200 ) {
					WP_AI_Workflows_Utilities::debug_log(
						'Non-200 response fetching result',
						'error',
						array(
							'status_code' => $result_code,
							'response'    => $result_body_raw,
						)
					);
					return new WP_Error( 'result_fetch_error', 'Error fetching result: HTTP ' . $result_code );
				}

				$result_body = json_decode( $result_body_raw, true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					WP_AI_Workflows_Utilities::debug_log(
						'Invalid JSON in result',
						'error',
						array(
							'error'    => json_last_error_msg(),
							'response' => $result_body_raw,
						)
					);
					return new WP_Error( 'result_parse_error', 'Error parsing result: ' . json_last_error_msg() );
				}

				WP_AI_Workflows_Utilities::debug_log(
					'Successfully retrieved result',
					'info',
					array(
						'contains_images' => isset( $result_body['images'] ),
						'contains_video'  => isset( $result_body['video'] ),
					)
				);

				return $result_body;
			}

			if ( $status_body['status'] === 'FAILED' || $status_body['status'] === 'CANCELED' ) {
				$error_message = isset( $status_body['error'] ) ? $status_body['error'] : 'Job ' . $status_body['status'];
				WP_AI_Workflows_Utilities::debug_log(
					'Job failed or canceled',
					'error',
					array(
						'status' => $status_body['status'],
						'error'  => $error_message,
					)
				);
				return new WP_Error( 'job_failed', $error_message );
			}

			// For IN_PROGRESS, QUEUED, etc., continue polling
		}

		WP_AI_Workflows_Utilities::debug_log(
			'Job timed out',
			'error',
			array(
				'request_id'   => $request_id,
				'max_attempts' => $max_attempts,
			)
		);
		return new WP_Error( 'timeout', 'Job did not complete within the allowed time' );
	}

	/**
	 * Get the base path for status and result URLs
	 *
	 * @param string $model_id The model ID
	 * @return string The base path for status and result URLs
	 */
	private function get_base_path_for_model( $model_id ) {
		if ( strpos( $model_id, 'hidream' ) !== false ) {
			return $model_id; // HiDream uses the full model ID
		} elseif ( strpos( $model_id, 'ideogram' ) !== false ) {
			return 'fal-ai/ideogram'; // Ideogram uses fal-ai/ideogram for requests
		} elseif ( strpos( $model_id, 'flux' ) !== false ) {
			return 'fal-ai/flux'; // Flux uses fal-ai/flux for requests
		} elseif ( strpos( $model_id, 'sana' ) !== false ) {
			return 'fal-ai/sana'; // Sana uses fal-ai/sana for requests
		} elseif ( strpos( $model_id, 'kling-video/v2/master' ) !== false ) {
			// For Kling models, the status/result URLs use fal-ai/kling-video (without the v2/master part)
			return 'fal-ai/kling-video';
		} elseif ( strpos( $model_id, 'veo2/image-to-video' ) !== false ) {
			// For Veo2 image-to-video, we use the full model path for submission
			// but for status/result, we use 'fal-ai/veo2'
			return 'fal-ai/veo2';
		} elseif ( strpos( $model_id, 'veo2' ) !== false ) {
			// For other Veo2 models (text-to-video)
			return 'fal-ai/veo2';
		}

		// Default case - use the model ID as is
		return $model_id;
	}

	/**
	 * Create a summary of the API response for logging
	 *
	 * @param array $response Full API response
	 * @param string $type Response type (image or video)
	 * @return array Summary of the response
	 */
	private function get_response_summary( $response, $type ) {
		$summary = array();

		if ( $type === 'image' ) {
			$summary['has_images']  = isset( $response['images'] ) && is_array( $response['images'] );
			$summary['image_count'] = $summary['has_images'] ? count( $response['images'] ) : 0;
			$summary['has_seed']    = isset( $response['seed'] );
		} elseif ( $type === 'video' ) {
			$summary['has_video'] = isset( $response['video'] ) && isset( $response['video']['url'] );
			$summary['video_url'] = $summary['has_video'] ? '[URL available]' : 'No video URL';
		}

		// Include request_id for queue-based responses
		if ( isset( $response['request_id'] ) ) {
			$summary['request_id'] = $response['request_id'];
			$summary['is_queued']  = true;
		}

		return $summary;
	}

	/**
	 * Get all supported models for frontend display
	 *
	 * @return array List of all models with metadata
	 */
	public function get_model_list_for_frontend() {
		$models = array();

		// Process text-to-image models
		foreach ( $this->text_to_image_models as $id => $model ) {
			$models[] = array(
				'id'         => $id,
				'name'       => $model['name'],
				'type'       => 'textToImage',
				'cost'       => $model['cost_per_image'],
				'cost_label' => '$' . number_format( $model['cost_per_image'], 2 ) . '/image',
			);
		}

		// Process image-to-video models
		foreach ( $this->image_to_video_models as $id => $model ) {
			$cost_label = '';
			if ( isset( $model['flat_cost'] ) ) {
				$cost_label = '$' . number_format( $model['flat_cost'], 2 ) . '/video';
			} elseif ( isset( $model['base_cost'] ) && isset( $model['cost_per_second'] ) ) {
				$cost_label = '$' . number_format( $model['base_cost'], 2 ) . ' (5s), +$' .
					number_format( $model['cost_per_second'], 2 ) . '/extra second';
			}

			$models[] = array(
				'id'         => $id,
				'name'       => $model['name'],
				'type'       => 'imageToVideo',
				'cost_label' => $cost_label,
			);
		}

		// Process text-to-video models
		foreach ( $this->text_to_video_models as $id => $model ) {
			$models[] = array(
				'id'         => $id,
				'name'       => $model['name'],
				'type'       => 'textToVideo',
				'cost'       => $model['flat_cost'],
				'cost_label' => '$' . number_format( $model['flat_cost'], 2 ) . '/video',
			);
		}

		return $models;
	}

	/* --------------------------------------------------------------------- */
	/* Dynamic Fal.ai catalog + OpenAPI-driven fields (scalable, no hardcode) */
	/* --------------------------------------------------------------------- */

	/**
	 * Fal.ai Model Search API (registry) base URL.
	 */
	const MODELS_API_URL = 'https://api.fal.ai/v1/models';

	/** Transient key for the normalized model catalog. */
	const CATALOG_CACHE_KEY = 'wpaw_fal_model_catalog';

	/** Transient key prefix for a single model's normalized input schema. */
	const SCHEMA_CACHE_PREFIX = 'wpaw_fal_model_schema_';

	/** Catalog / schema cache lifetime (12h — the registry changes rarely). */
	const CATALOG_TTL = 43200;

	/**
	 * Fal.ai generation categories we surface in the picker. The registry contains
	 * many non-generation entries; we restrict to media generation so the picker is
	 * useful. Each maps to a coarse group (image | video | audio) for the UI.
	 *
	 * @return array<string,string> category => group
	 */
	private function catalog_categories() {
		return array(
			'text-to-image'  => 'image',
			'image-to-image' => 'image',
			'text-to-video'  => 'video',
			'image-to-video' => 'video',
			'video-to-video' => 'video',
			'text-to-audio'  => 'audio',
			'text-to-speech' => 'audio',
			'audio-to-audio' => 'audio',
		);
	}

	/**
	 * Validate that a Fal-supplied URL points at a Fal host before we follow it
	 * (SSRF guard for the queue status/result URLs echoed back to us).
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	private function is_allowed_fal_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}
		if ( ! wp_http_validate_url( $url ) ) {
			return false;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return false;
		}
		$host = strtolower( $host );
		return ( 'fal.run' === $host
			|| 'fal.ai' === $host
			|| substr( $host, -8 ) === '.fal.run'
			|| substr( $host, -7 ) === '.fal.ai' );
	}

	/**
	 * Sanitize a Fal model / endpoint id (namespaced, may contain slashes/dots).
	 *
	 * @param string $model Raw model id.
	 * @return string Sanitized id (empty string if invalid).
	 */
	public static function sanitize_model_id( $model ) {
		$model = is_string( $model ) ? trim( $model ) : '';
		if ( '' === $model ) {
			return '';
		}
		// Allow the exact character set Fal endpoint ids use.
		if ( ! preg_match( '#^[A-Za-z0-9._/-]{1,200}$#', $model ) ) {
			return '';
		}
		return $model;
	}

	/**
	 * Recursively sanitize a free-form generation input map before it is sent as
	 * the JSON request body to a Fal model. Keys are constrained to a safe set;
	 * string values are cleaned with sanitize_textarea_field (newlines preserved);
	 * numbers/booleans pass through; nested arrays recurse.
	 *
	 * @param array $inputs Raw inputs.
	 * @return array Sanitized inputs.
	 */
	public static function sanitize_generation_inputs( $inputs ) {
		if ( ! is_array( $inputs ) ) {
			return array();
		}

		$clean = array();
		foreach ( $inputs as $key => $value ) {
			// Preserve list vs assoc: numeric keys stay numeric.
			if ( is_int( $key ) ) {
				$safe_key = $key;
			} else {
				$safe_key = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $key );
				if ( '' === $safe_key ) {
					continue;
				}
			}

			if ( is_array( $value ) ) {
				$clean[ $safe_key ] = self::sanitize_generation_inputs( $value );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$clean[ $safe_key ] = $value;
			} elseif ( is_string( $value ) ) {
				$clean[ $safe_key ] = sanitize_textarea_field( $value );
			}
			// Drop nulls/objects/other.
		}

		return $clean;
	}

	/**
	 * Fetch (and cache) the normalized Fal.ai model catalog for the generation
	 * categories. Each entry: id, name, category, group (image|video|audio),
	 * description, thumbnail, tags.
	 *
	 * @param bool $refresh When true, bypass and rebuild the cache.
	 * @return array|WP_Error List of models or error.
	 */
	public function get_model_catalog( $refresh = false ) {
		if ( ! $refresh ) {
			$cached = get_transient( self::CATALOG_CACHE_KEY );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}

		$api_key = WP_AI_Workflows_Utilities::get_fal_ai_api_key();
		$headers = array( 'Accept' => 'application/json' );
		if ( ! empty( $api_key ) ) {
			// Optional, but raises the rate limit for the registry.
			$headers['Authorization'] = 'Key ' . $api_key;
		}

		$catalog    = array();
		$seen       = array();
		$categories = $this->catalog_categories();

		foreach ( $categories as $category => $group ) {
			$cursor = '';
			$pages  = 0;

			do {
				$query = array(
					'category' => $category,
					'status'   => 'active',
					'limit'    => 100,
				);
				if ( '' !== $cursor ) {
					$query['cursor'] = $cursor;
				}

				$url      = add_query_arg( array_map( 'rawurlencode', $query ), self::MODELS_API_URL );
				$response = wp_remote_get(
					$url,
					array(
						'headers' => $headers,
						'timeout' => 20,
					)
				);

				if ( is_wp_error( $response ) ) {
					WP_AI_Workflows_Utilities::debug_log(
						'Fal model catalog fetch error',
						'warning',
						array(
							'category' => $category,
							'error'    => $response->get_error_message(),
						)
					);
					break;
				}

				$code = wp_remote_retrieve_response_code( $response );
				$body = json_decode( wp_remote_retrieve_body( $response ), true );

				if ( 200 !== $code || ! is_array( $body ) || empty( $body['models'] ) ) {
					break;
				}

				foreach ( $body['models'] as $model ) {
					$id = isset( $model['endpoint_id'] ) ? $model['endpoint_id'] : '';
					if ( '' === $id || isset( $seen[ $id ] ) ) {
						continue;
					}
					$seen[ $id ] = true;

					$meta      = isset( $model['metadata'] ) && is_array( $model['metadata'] ) ? $model['metadata'] : array();
					$catalog[] = array(
						'id'          => $id,
						'name'        => isset( $meta['display_name'] ) && '' !== $meta['display_name'] ? $meta['display_name'] : $id,
						'category'    => isset( $meta['category'] ) ? $meta['category'] : $category,
						'group'       => $group,
						'description' => isset( $meta['description'] ) ? $meta['description'] : '',
						'thumbnail'   => isset( $meta['thumbnail_url'] ) ? $meta['thumbnail_url'] : '',
						'tags'        => isset( $meta['tags'] ) && is_array( $meta['tags'] ) ? array_values( $meta['tags'] ) : array(),
					);
				}

				$cursor = isset( $body['next_cursor'] ) && is_string( $body['next_cursor'] ) ? $body['next_cursor'] : '';
				$has_more = ! empty( $body['has_more'] ) && '' !== $cursor;
				++$pages;
				// Cap pages per category so a huge category can't stall the request.
			} while ( $has_more && $pages < 3 );
		}

		if ( empty( $catalog ) ) {
			return new WP_Error( 'catalog_empty', 'Could not retrieve any models from the Fal.ai registry.' );
		}

		// Stable ordering: group, then name.
		usort(
			$catalog,
			static function ( $a, $b ) {
				if ( $a['group'] !== $b['group'] ) {
					return strcmp( $a['group'], $b['group'] );
				}
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		set_transient( self::CATALOG_CACHE_KEY, $catalog, self::CATALOG_TTL );

		return $catalog;
	}

	/**
	 * Fetch (and cache) a single model's OpenAPI 3.0 schema and normalize its input
	 * object into a flat list of render-ready field descriptors.
	 *
	 * @param string $model   Fal endpoint id.
	 * @param bool   $refresh When true, bypass and rebuild the cache.
	 * @return array|WP_Error { fields: [...], required: [...] } or error.
	 */
	public function get_model_schema( $model, $refresh = false ) {
		$model = self::sanitize_model_id( $model );
		if ( '' === $model ) {
			return new WP_Error( 'invalid_model', 'Invalid model id' );
		}

		$cache_key = self::SCHEMA_CACHE_PREFIX . md5( $model );
		if ( ! $refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && isset( $cached['fields'] ) ) {
				return $cached;
			}
		}

		$api_key = WP_AI_Workflows_Utilities::get_fal_ai_api_key();
		$headers = array( 'Accept' => 'application/json' );
		if ( ! empty( $api_key ) ) {
			$headers['Authorization'] = 'Key ' . $api_key;
		}

		$url = add_query_arg(
			array(
				'endpoint_id' => rawurlencode( $model ),
				'expand'      => 'openapi-3.0',
			),
			self::MODELS_API_URL
		);

		$response = wp_remote_get(
			$url,
			array(
				'headers' => $headers,
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'schema_fetch_error', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) || empty( $body['models'][0] ) ) {
			return new WP_Error( 'schema_unavailable', 'The model schema could not be retrieved (HTTP ' . intval( $code ) . ').' );
		}

		$model_obj = $body['models'][0];
		$openapi   = isset( $model_obj['openapi'] ) && is_array( $model_obj['openapi'] ) ? $model_obj['openapi'] : array();

		$normalized = $this->normalize_openapi_input( $openapi );

		$meta   = isset( $model_obj['metadata'] ) && is_array( $model_obj['metadata'] ) ? $model_obj['metadata'] : array();
		$result = array(
			'model'    => $model,
			'name'     => isset( $meta['display_name'] ) ? $meta['display_name'] : $model,
			'category' => isset( $meta['category'] ) ? $meta['category'] : '',
			'fields'   => $normalized['fields'],
			'required' => $normalized['required'],
		);

		if ( empty( $result['fields'] ) ) {
			return new WP_Error( 'schema_empty', 'No input fields were found in the model schema.' );
		}

		set_transient( $cache_key, $result, self::CATALOG_TTL );

		return $result;
	}

	/**
	 * Parse an OpenAPI 3.0 document from Fal and turn the request input schema into
	 * a flat, render-ready field list. Resolves $ref, anyOf/allOf (best-effort),
	 * enums, defaults, numeric bounds and string formats.
	 *
	 * @param array $openapi Decoded OpenAPI document.
	 * @return array { fields: array<int,array>, required: string[] }
	 */
	private function normalize_openapi_input( array $openapi ) {
		$components = isset( $openapi['components']['schemas'] ) && is_array( $openapi['components']['schemas'] )
			? $openapi['components']['schemas']
			: array();

		$input_schema = $this->locate_input_schema( $openapi, $components );

		$fields   = array();
		$required = array();

		if ( is_array( $input_schema ) ) {
			$required   = isset( $input_schema['required'] ) && is_array( $input_schema['required'] )
				? array_values( $input_schema['required'] )
				: array();
			$properties = isset( $input_schema['properties'] ) && is_array( $input_schema['properties'] )
				? $input_schema['properties']
				: array();

			$order = 0;
			foreach ( $properties as $name => $prop ) {
				$field = $this->describe_property( $name, $prop, $components, in_array( $name, $required, true ) );
				if ( null !== $field ) {
					$field['order'] = isset( $prop['x-fal-order'] ) ? intval( $prop['x-fal-order'] ) : $order;
					$fields[]       = $field;
				}
				++$order;
			}

			// Preserve fal's declared order when present, otherwise property order.
			usort(
				$fields,
				static function ( $a, $b ) {
					return $a['order'] <=> $b['order'];
				}
			);
			foreach ( $fields as &$f ) {
				unset( $f['order'] );
			}
			unset( $f );
		}

		return array(
			'fields'   => $fields,
			'required' => $required,
		);
	}

	/**
	 * Find the request-body input schema in an OpenAPI doc: the first POST
	 * operation's application/json request body schema, resolved through $ref.
	 * Falls back to a components schema whose name ends in "Input".
	 *
	 * @param array $openapi    Decoded OpenAPI doc.
	 * @param array $components components.schemas map.
	 * @return array|null
	 */
	private function locate_input_schema( array $openapi, array $components ) {
		if ( isset( $openapi['paths'] ) && is_array( $openapi['paths'] ) ) {
			foreach ( $openapi['paths'] as $methods ) {
				if ( ! is_array( $methods ) || ! isset( $methods['post'] ) ) {
					continue;
				}
				$body = isset( $methods['post']['requestBody']['content']['application/json']['schema'] )
					? $methods['post']['requestBody']['content']['application/json']['schema']
					: null;
				if ( is_array( $body ) ) {
					$resolved = $this->resolve_ref( $body, $components );
					if ( isset( $resolved['properties'] ) ) {
						return $resolved;
					}
				}
			}
		}

		// Fallback: a component schema named *Input.
		foreach ( $components as $name => $schema ) {
			if ( is_string( $name ) && substr( $name, -5 ) === 'Input' && isset( $schema['properties'] ) ) {
				return $schema;
			}
		}

		return null;
	}

	/**
	 * Resolve a local $ref against components.schemas (one level; good enough for
	 * Fal schemas which are shallow). Returns the schema unchanged if no $ref.
	 *
	 * @param array $schema     Schema node (may contain $ref).
	 * @param array $components components.schemas map.
	 * @return array
	 */
	private function resolve_ref( array $schema, array $components ) {
		if ( isset( $schema['$ref'] ) && is_string( $schema['$ref'] ) ) {
			$ref  = $schema['$ref'];
			$name = substr( $ref, strrpos( $ref, '/' ) + 1 );
			if ( isset( $components[ $name ] ) && is_array( $components[ $name ] ) ) {
				return $components[ $name ];
			}
		}
		return $schema;
	}

	/**
	 * Turn a single OpenAPI property into a render-ready field descriptor.
	 *
	 * @param string $name       Property name.
	 * @param array  $prop       Property schema.
	 * @param array  $components components.schemas map (for $ref/anyOf resolution).
	 * @param bool   $required   Whether the property is required.
	 * @return array|null
	 */
	private function describe_property( $name, $prop, array $components, $required ) {
		if ( ! is_array( $prop ) ) {
			return null;
		}

		$prop = $this->resolve_ref( $prop, $components );

		// Flatten a common Fal pattern: anyOf [ enumRef, {type:object ...}, null ].
		if ( isset( $prop['anyOf'] ) && is_array( $prop['anyOf'] ) ) {
			$prop = $this->flatten_any_of( $prop, $components );
		}
		if ( isset( $prop['allOf'] ) && is_array( $prop['allOf'] ) && isset( $prop['allOf'][0] ) ) {
			$merged = $this->resolve_ref( $prop['allOf'][0], $components );
			$prop   = array_merge( $merged, array_diff_key( $prop, array( 'allOf' => 1 ) ) );
		}

		$json_type = isset( $prop['type'] ) ? $prop['type'] : ( isset( $prop['enum'] ) ? 'string' : 'string' );
		$enum      = isset( $prop['enum'] ) && is_array( $prop['enum'] ) ? array_values( $prop['enum'] ) : null;
		$format    = isset( $prop['format'] ) ? $prop['format'] : '';
		$title     = isset( $prop['title'] ) ? $prop['title'] : '';
		$desc      = isset( $prop['description'] ) ? $prop['description'] : '';

		$control            = 'text';
		$supports_variables = false;

		if ( null !== $enum ) {
			$control = 'enum';
		} elseif ( 'integer' === $json_type || 'number' === $json_type ) {
			$control = 'number';
		} elseif ( 'boolean' === $json_type ) {
			$control = 'boolean';
		} elseif ( 'object' === $json_type || 'array' === $json_type ) {
			$control = 'json';
		} else {
			// string.
			$lower = strtolower( $name );
			if ( 'uri' === $format || 'url' === $format
				|| false !== strpos( $lower, 'image_url' )
				|| false !== strpos( $lower, '_url' )
				|| 'image' === $lower ) {
				$control            = 'url';
				$supports_variables = true;
			} elseif ( 'prompt' === $lower || false !== strpos( $lower, 'prompt' ) || strlen( $desc ) > 80 ) {
				$control            = 'textarea';
				$supports_variables = true;
			} else {
				$control            = 'text';
				$supports_variables = true;
			}
		}

		$field = array(
			'name'              => $name,
			'label'             => '' !== $title ? $title : $this->humanize( $name ),
			'control'           => $control,
			'jsonType'          => $json_type,
			'format'            => $format,
			'description'       => $desc,
			'required'          => (bool) $required,
			'supportsVariables' => $supports_variables,
		);

		if ( array_key_exists( 'default', $prop ) ) {
			$field['default'] = $prop['default'];
		}
		if ( null !== $enum ) {
			$field['options'] = $enum;
		}
		if ( isset( $prop['minimum'] ) ) {
			$field['minimum'] = $prop['minimum'];
		}
		if ( isset( $prop['maximum'] ) ) {
			$field['maximum'] = $prop['maximum'];
		}

		return $field;
	}

	/**
	 * Best-effort flatten of an anyOf: prefer a branch that carries an enum, then a
	 * simple scalar branch, then the first object branch; ignore explicit null.
	 *
	 * @param array $prop       Property with anyOf.
	 * @param array $components components map.
	 * @return array Flattened property schema.
	 */
	private function flatten_any_of( array $prop, array $components ) {
		$branches = array();
		foreach ( $prop['anyOf'] as $branch ) {
			if ( ! is_array( $branch ) ) {
				continue;
			}
			$branch = $this->resolve_ref( $branch, $components );
			if ( isset( $branch['type'] ) && 'null' === $branch['type'] ) {
				continue;
			}
			$branches[] = $branch;
		}

		$chosen = null;
		foreach ( $branches as $branch ) {
			if ( isset( $branch['enum'] ) ) {
				$chosen = $branch;
				break;
			}
		}
		if ( null === $chosen ) {
			foreach ( $branches as $branch ) {
				$type = isset( $branch['type'] ) ? $branch['type'] : '';
				if ( in_array( $type, array( 'string', 'integer', 'number', 'boolean' ), true ) ) {
					$chosen = $branch;
					break;
				}
			}
		}
		if ( null === $chosen && ! empty( $branches ) ) {
			$chosen = $branches[0];
		}
		if ( null === $chosen ) {
			return $prop;
		}

		// Carry over sibling metadata (title/description/default) that lives on the
		// parent rather than the chosen branch.
		foreach ( array( 'title', 'description', 'default' ) as $k ) {
			if ( ! isset( $chosen[ $k ] ) && isset( $prop[ $k ] ) ) {
				$chosen[ $k ] = $prop[ $k ];
			}
		}

		return $chosen;
	}

	/**
	 * Convert snake_case / kebab-case to a human label.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	private function humanize( $name ) {
		$name = str_replace( array( '_', '-' ), ' ', (string) $name );
		return ucwords( trim( $name ) );
	}

	/**
	 * Generic generation: submit the assembled inputs to the model endpoint and
	 * return the completed result. Works for ANY Fal model (image/video/audio) —
	 * no per-model parameter logic.
	 *
	 * @param string $model  Fal endpoint id.
	 * @param array  $inputs Assembled request body (already sanitized/typed).
	 * @return array|WP_Error Completed result payload or error.
	 */
	public function submit_generation( $model, $inputs ) {
		$model = self::sanitize_model_id( $model );
		if ( '' === $model ) {
			return new WP_Error( 'invalid_model', 'Invalid model id' );
		}

		$api_key = WP_AI_Workflows_Utilities::get_fal_ai_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_api_key', 'Fal.ai API key is required' );
		}

		if ( ! is_array( $inputs ) ) {
			$inputs = array();
		}

		$endpoint = self::API_BASE_URL . $model; // https://queue.fal.run/{model}

		WP_AI_Workflows_Utilities::debug_log(
			'Fal generic generation request',
			'debug',
			array(
				'endpoint'    => $endpoint,
				'model'       => $model,
				'input_keys'  => array_keys( $inputs ),
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Key ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $inputs ),
				'timeout' => self::VIDEO_TIMEOUT,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_error', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'invalid_response', 'Invalid response from Fal.ai' );
		}

		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $body['detail'] )
				? ( is_string( $body['detail'] ) ? $body['detail'] : wp_json_encode( $body['detail'] ) )
				: ( isset( $body['error'] ) ? $body['error'] : 'HTTP ' . intval( $code ) );
			return new WP_Error( 'api_error', $msg );
		}

		// Queue mode: poll the status/response URLs echoed back to us.
		if ( isset( $body['request_id'] ) && isset( $body['status_url'] ) ) {
			return $this->poll_generic_queue(
				$body['status_url'],
				isset( $body['response_url'] ) ? $body['response_url'] : '',
				$api_key
			);
		}

		// Synchronous mode (some models answer inline).
		return $body;
	}

	/**
	 * Poll a Fal queue using the status/response URLs returned by the submit call.
	 * No model-specific base-path derivation — fully generic.
	 *
	 * @param string $status_url   Queue status URL.
	 * @param string $response_url Queue response (result) URL.
	 * @param string $api_key      Fal API key.
	 * @param int    $max_attempts Max poll attempts.
	 * @return array|WP_Error
	 */
	private function poll_generic_queue( $status_url, $response_url, $api_key, $max_attempts = 60 ) {
		if ( ! $this->is_allowed_fal_url( $status_url ) ) {
			return new WP_Error( 'bad_status_url', 'Refusing to poll a non-Fal status URL.' );
		}

		$auth    = array( 'Authorization' => 'Key ' . $api_key );
		$backoff = 1;

		for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
			if ( $attempt > 0 ) {
				sleep( (int) $backoff );
				$backoff = min( $backoff * 1.5, 10 );
			}

			$status_response = wp_remote_get(
				$status_url,
				array(
					'headers' => $auth,
					'timeout' => 15,
				)
			);
			if ( is_wp_error( $status_response ) ) {
				continue;
			}
			if ( 200 !== wp_remote_retrieve_response_code( $status_response ) ) {
				continue;
			}

			$status_body = json_decode( wp_remote_retrieve_body( $status_response ), true );
			if ( ! is_array( $status_body ) || ! isset( $status_body['status'] ) ) {
				continue;
			}

			$status = $status_body['status'];

			if ( 'COMPLETED' === $status ) {
				$result_url = '';
				if ( isset( $status_body['response_url'] ) && $this->is_allowed_fal_url( $status_body['response_url'] ) ) {
					$result_url = $status_body['response_url'];
				} elseif ( $this->is_allowed_fal_url( $response_url ) ) {
					$result_url = $response_url;
				}
				if ( '' === $result_url ) {
					return new WP_Error( 'no_result_url', 'Job completed but no result URL was available.' );
				}

				$result_response = wp_remote_get(
					$result_url,
					array(
						'headers' => $auth,
						'timeout' => 300,
					)
				);
				if ( is_wp_error( $result_response ) ) {
					return new WP_Error( 'result_fetch_error', $result_response->get_error_message() );
				}
				if ( 200 !== wp_remote_retrieve_response_code( $result_response ) ) {
					return new WP_Error( 'result_fetch_error', 'Error fetching result: HTTP ' . wp_remote_retrieve_response_code( $result_response ) );
				}

				$result_body = json_decode( wp_remote_retrieve_body( $result_response ), true );
				if ( ! is_array( $result_body ) ) {
					return new WP_Error( 'result_parse_error', 'Could not parse the generation result.' );
				}
				return $result_body;
			}

			if ( 'FAILED' === $status || 'CANCELED' === $status || 'ERROR' === $status ) {
				$err = isset( $status_body['error'] ) ? $status_body['error'] : ( 'Job ' . $status );
				return new WP_Error( 'job_failed', is_string( $err ) ? $err : wp_json_encode( $err ) );
			}
			// IN_QUEUE / IN_PROGRESS -> keep polling.
		}

		return new WP_Error( 'timeout', 'Generation did not complete within the allowed time.' );
	}

	/**
	 * Robustly extract media URL(s) from an arbitrary Fal result payload. Handles
	 * images[]/image/video/audio/audio_url/file shapes plus a recursive fallback.
	 *
	 * @param array $result Completed result payload.
	 * @return array { urls: string[], media_type: string, items: array }
	 */
	public function extract_media_outputs( $result ) {
		$urls  = array();
		$items = array();
		$type  = '';

		if ( ! is_array( $result ) ) {
			return array(
				'urls'       => array(),
				'media_type' => '',
				'items'      => array(),
			);
		}

		$push = static function ( $node, $default_type ) use ( &$urls, &$items, &$type ) {
			if ( is_array( $node ) && isset( $node['url'] ) && is_string( $node['url'] ) ) {
				$urls[]  = $node['url'];
				$items[] = array(
					'url'    => $node['url'],
					'type'   => isset( $node['content_type'] ) ? $node['content_type'] : $default_type,
					'width'  => isset( $node['width'] ) ? $node['width'] : null,
					'height' => isset( $node['height'] ) ? $node['height'] : null,
				);
				if ( '' === $type ) {
					$type = $default_type;
				}
			} elseif ( is_string( $node ) && preg_match( '#^https?://#', $node ) ) {
				$urls[]  = $node;
				$items[] = array( 'url' => $node, 'type' => $default_type );
				if ( '' === $type ) {
					$type = $default_type;
				}
			}
		};

		// images: [ {url,...} ] or [ "url" ].
		if ( isset( $result['images'] ) && is_array( $result['images'] ) ) {
			foreach ( $result['images'] as $img ) {
				$push( $img, 'image' );
			}
		}
		if ( isset( $result['image'] ) ) {
			$push( $result['image'], 'image' );
		}
		if ( isset( $result['video'] ) ) {
			$push( $result['video'], 'video' );
		}
		if ( isset( $result['videos'] ) && is_array( $result['videos'] ) ) {
			foreach ( $result['videos'] as $v ) {
				$push( $v, 'video' );
			}
		}
		if ( isset( $result['audio'] ) ) {
			$push( $result['audio'], 'audio' );
		}
		if ( isset( $result['audio_url'] ) ) {
			$push( $result['audio_url'], 'audio' );
		}
		if ( isset( $result['file'] ) ) {
			$push( $result['file'], '' );
		}

		// Recursive fallback if nothing matched the well-known keys.
		if ( empty( $urls ) ) {
			$this->collect_urls_recursive( $result, $urls, $items );
			if ( ! empty( $items ) && '' === $type ) {
				$type = $this->guess_media_type( $items[0]['url'] );
			}
		}

		if ( '' === $type && ! empty( $urls ) ) {
			$type = $this->guess_media_type( $urls[0] );
		}

		// De-duplicate.
		$urls = array_values( array_unique( $urls ) );

		return array(
			'urls'       => $urls,
			'media_type' => $type,
			'items'      => $items,
		);
	}

	/**
	 * Recursively collect any http(s) URLs found under `url` keys or bare string
	 * values that look like media URLs.
	 *
	 * @param mixed $node  Current node.
	 * @param array $urls  Accumulator (by ref).
	 * @param array $items Accumulator (by ref).
	 * @return void
	 */
	private function collect_urls_recursive( $node, array &$urls, array &$items ) {
		if ( is_array( $node ) ) {
			foreach ( $node as $key => $value ) {
				if ( 'url' === $key && is_string( $value ) && preg_match( '#^https?://#', $value ) ) {
					$urls[]  = $value;
					$items[] = array( 'url' => $value, 'type' => $this->guess_media_type( $value ) );
				} else {
					$this->collect_urls_recursive( $value, $urls, $items );
				}
			}
		} elseif ( is_string( $node ) && preg_match( '#^https?://\S+\.(png|jpe?g|webp|gif|mp4|webm|mov|mp3|wav|ogg|m4a)#i', $node ) ) {
			$urls[]  = $node;
			$items[] = array( 'url' => $node, 'type' => $this->guess_media_type( $node ) );
		}
	}

	/**
	 * Guess a coarse media type from a URL extension.
	 *
	 * @param string $url URL.
	 * @return string image|video|audio|''
	 */
	private function guess_media_type( $url ) {
		$path = wp_parse_url( (string) $url, PHP_URL_PATH );
		$ext  = strtolower( (string) pathinfo( (string) $path, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp', 'svg' ), true ) ) {
			return 'image';
		}
		if ( in_array( $ext, array( 'mp4', 'webm', 'mov', 'mkv', 'avi' ), true ) ) {
			return 'video';
		}
		if ( in_array( $ext, array( 'mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac' ), true ) ) {
			return 'audio';
		}
		return '';
	}
}
