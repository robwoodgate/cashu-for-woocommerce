<?php

declare(strict_types=1);

namespace Cashu\WC\Helpers;

/**
 * Helper class to render the order_states as a custom field in global settings form.
 */
class OrderStates {
	const NEW     = 'New';
	const PENDING = 'Pending';
	const SETTLED = 'Settled';
	const PAID    = 'Paid';
	const EXPIRED = 'Expired';
	const IGNORE  = 'CASHU_IGNORE';

	public function getDefaultOrderStateMappings(): array {
		return array(
			self::NEW     => 'wc-pending',
			self::PENDING => 'wc-pending',
			self::PAID    => 'wc-processing',
			self::SETTLED => self::IGNORE,
			self::EXPIRED => 'wc-cancelled',
		);
	}

	public function getOrderStateLabels(): array {
		return array(
			self::NEW     => 'New',
			self::PENDING => 'Pending',
			self::PAID    => 'Paid',
			self::SETTLED => 'Settled',
			self::EXPIRED => 'Expired',
		);
	}

	public function renderOrderStatesHtml( $value ) {
		$cashuStates   = $this->getOrderStateLabels();
		$defaultStates = $this->getDefaultOrderStateMappings();

		$wcStates    = wc_get_order_statuses();
		$wcStates    =
		array(
			self::IGNORE => '- no mapping / defaults -',
		) + $wcStates;
		$orderStates = get_option( $value['id'] );
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">Order States:</th>
			<td class="forminp" id="<?php echo esc_attr( $value['id'] ); ?>">
				<table cellspacing="0">
					<?php foreach ( $cashuStates as $cashuState => $cashuName ) { ?>
						<tr>
							<th><?php echo esc_html( $cashuName ); ?></th>
							<td>
								<select name="<?php echo esc_attr( $value['id'] ); ?>[
								<?php echo esc_html( $cashuState ); ?>
												]">
									<?php
									foreach ( $wcStates as $wcState => $wcName ) {
										$selectedOption = $orderStates[ $cashuState ];

										if ( true === empty( $selectedOption ) ) {
											$selectedOption = $defaultStates[ $cashuState ];
										}

										if ( $selectedOption === $wcState ) {
											echo '<option value="' .
											esc_attr( $wcState ) .
											'" selected>' .
											esc_html( $wcName ) .
											'</option>' .
											PHP_EOL;
										} else {
											echo '<option value="' .
											esc_attr( $wcState ) .
											'">' .
											esc_html( $wcName ) .
											'</option>' .
											PHP_EOL;
										}
									}
									?>
								</select>
							</td>
						</tr>
						<?php } ?>
				</table>
				<p class="description">
			<?php
			echo esc_html(
				'By keeping default behavior for the "Settled" status you make sure that WooCommerce handles orders of virtual and downloadable products only properly and set those orders to "complete" instead of "processing" like for orders containing physical products.'
			);
			?>
				</p>
			</td>
		</tr>
			<?php
	}
}
