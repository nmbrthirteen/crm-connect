<?php

namespace CrmConnect\Mapping;

defined( 'ABSPATH' ) || exit;

final class ProfileRepository {

	private const OPTION = 'crm_connect_profiles';

	/** @var Profile[]|null */
	private ?array $memo = null;

	/** @return Profile[] */
	public function all(): array {
		if ( $this->memo !== null ) {
			return $this->memo;
		}
		$stored   = get_option( self::OPTION, [] );
		$profiles = [];
		foreach ( (array) $stored as $data ) {
			$profiles[] = Profile::from_array( (array) $data );
		}
		$this->memo = $profiles;
		return $this->memo;
	}

	public function find( string $id ): ?Profile {
		foreach ( $this->all() as $profile ) {
			if ( $profile->id === $id ) {
				return $profile;
			}
		}
		return null;
	}

	public function save( Profile $profile ): void {
		$all      = [];
		$replaced = false;
		foreach ( $this->all() as $existing ) {
			if ( $existing->id === $profile->id ) {
				if ( $profile->created_at === '' ) {
					$profile->created_at = $existing->created_at;
				}
				$all[]    = $profile->to_array();
				$replaced = true;
			} else {
				$all[] = $existing->to_array();
			}
		}
		if ( ! $replaced ) {
			if ( $profile->created_at === '' ) {
				$profile->created_at = gmdate( 'Y-m-d H:i:s' );
			}
			$all[] = $profile->to_array();
		}
		update_option( self::OPTION, $all, false );
		$this->memo = null;
	}

	public function delete( string $id ): void {
		$all = [];
		foreach ( $this->all() as $existing ) {
			if ( $existing->id !== $id ) {
				$all[] = $existing->to_array();
			}
		}
		update_option( self::OPTION, $all, false );
		$this->memo = null;
	}

	/** @return Profile[] */
	public function for_form( string $form_id, string $form_name ): array {
		$matches = [];
		foreach ( $this->all() as $profile ) {
			if ( $profile->enabled && $this->matches( $profile, $form_id, $form_name ) ) {
				$matches[] = $profile;
			}
		}
		return $matches;
	}

	public function has_for_form( string $form_id, string $form_name ): bool {
		return $this->for_form( $form_id, $form_name ) !== [];
	}

	private function matches( Profile $profile, string $form_id, string $form_name ): bool {
		if ( $profile->form_name !== '' && $profile->form_name === $form_name ) {
			return true;
		}
		return $profile->form_id !== '' && $profile->form_id === $form_id;
	}
}
