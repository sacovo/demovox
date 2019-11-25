<?php

Namespace Demovox;

class SignSteps
{
	/** @var $nonceId string */
	private $nonceId = null;

	/** @var $textColor string RGB */
	private $textColor = [0, 0, 0];

	public function __construct($nonceId)
	{
		$this->nonceId = $nonceId;
	}

	public function step1()
	{
		$source    = isset($_REQUEST['src']) ? sanitize_text_field($_REQUEST['src']) : '';
		$textOptin = Config::getValueByUserlang('text_optin');
		include Infos::getPluginDir() . 'public/partials/sign-1.php';
	}

	protected function saveStep1()
	{
		$dbSign = new DbSignatures();
		$lang      = Infos::getUserLanguage();
		$src       = sanitize_text_field($_REQUEST['src']);
		$nameFirst = sanitize_text_field($_REQUEST['name_first']);
		$nameLast  = sanitize_text_field($_REQUEST['name_last']);
		$mail      = sanitize_email($_REQUEST['mail']);
		$phone     = sanitize_text_field(str_replace(' ', '', $_REQUEST['phone']));
		$optIn     = isset($_REQUEST['is_optin']) && $_REQUEST['is_optin'] ? 1 : 0;

		if (strlen($nameFirst) < 3 || strlen($nameFirst) > 255
			|| strlen($nameLast) < 3
			|| strlen($nameLast) > 255
			|| !is_email($mail)
			|| ($phone && preg_match('/^((\+[1-9])|(0\d[1-9]))\d+$/', $phone) === false)
		) {
			Core::showError('Invalid form values received', 400);
		}

		$data = [
			'language'   => $lang,
			'first_name' => $nameFirst,
			'last_name'  => $nameLast,
			'mail'       => $mail,
			'phone'      => $phone,
		];
		if (Config::getValue('save_ip') && Config::getValue('encrypt_signees') !== 'disabled') {
			$data['ip_address'] = Infos::getClientIp();
		}
		if ($src) {
			$data['source'] = $src;
		}
		if ($optinMode = $this->getOptinMode(1)) {
			$optIn            = ($optinMode === 'optOut' || $optinMode === 'optOutChk') ? !$optIn : $optIn;
			$data['is_optin'] = $optIn;
		}
		$success = $dbSign->insert($data);
		if (!$success) {
			Core::showError('DB insert failed: ' . Db::getError(), 500);
		}
		$signId     = Db::getInsertId();
		$successUpd = $dbSign->updateStatus(
			['serial' => Strings::getSerial($signId)],
			['ID' => $signId]
		);
		if (!$successUpd) {
			Core::logMessage('Could not save serial for ID=' . $signId . '. Reason:' . Db::getError());
		}
		$this->setSessionVar('signId', $signId);
	}

	public function step2()
	{
		$this->verifyNonce();
		$this->saveStep1();

		// Prepare view variables
		$textOptin         = Config::getValueByUserlang('text_optin');
		$apiAddressEnabled = !empty(Config::getValue('api_address_url'));
		$cantons           = i18n::$cantons;
		$allowSwissAbroad  = Config::getValue('swiss_abroad_allow');

		// Render view
		include Infos::getPluginDir() . 'public/partials/sign-2.php';
		wp_die(); // Die, as this is the response on a AJAX request TODO: recognize AJAX
	}

	/**
	 * Update signature with form data
	 *
	 * @param int $signId
	 * @param int $isEncrypted
	 * @param     $guid
	 *
	 * @return string
	 */
	protected function saveStep2($signId, $guid, $isEncrypted)
	{
		$dbSign = new DbSignatures();
		// Load and sanitize form data
		$birthDate       = sanitize_text_field($_REQUEST['birth_date']);
		$birthDateParsed = date_parse($birthDate);
		$street          = sanitize_text_field($_REQUEST['street']);
		$streetNo        = sanitize_text_field($_REQUEST['street_no']);
		$zip             = sanitize_text_field($_REQUEST['zip']);
		$city            = sanitize_text_field($_REQUEST['city']);
		$gdeId           = (string)intval($_REQUEST['gde_id']);
		$gdeZip          = sanitize_text_field($_REQUEST['gde_zip']);
		$gdeName         = sanitize_text_field($_REQUEST['gde_name']);
		$gdeCanton       = strtolower(sanitize_text_field($_REQUEST['gde_canton']));

		// Validate form data
		if (!isset($_REQUEST['swiss_abroad']) || !$_REQUEST['swiss_abroad']) {
			$country = i18n::$defaultCountry;
		} else {
			$country   = isset($_REQUEST['country']) ? strtoupper(sanitize_text_field($_REQUEST['country'])) : null;
			$zip       = sanitize_text_field($_REQUEST['zip_abroad']);
			$city      = sanitize_text_field($_REQUEST['city_abroad']);
			$countries = Strings::getCountries();
			if (!$country || !isset($countries[$country])) {
				Core::showError('Invalid country value received: ' . $country, 400);
			}
		}
		if (
			preg_match('/^([0-2]?[0-9]|3[0-2])\.(0?[1-9]|1[0-2])\.\d{2,4}$/', $birthDate) === false
			|| $birthDateParsed['year'] === false || $birthDateParsed['month'] === false || $birthDateParsed['day'] === false
			|| strlen($street) < 4 || strlen($street) > 127
			|| strlen($streetNo) < 1 || strlen($streetNo) > 5
			|| strlen($zip) < 4 || strlen($zip) > 16
			|| strlen($city) < 2 || strlen($city) > 64
			|| strlen($gdeId) > 5
			|| strlen($gdeZip) > 4
			|| strlen($gdeName) < 2 || strlen($gdeName) > 45
			|| !isset($gdeCanton) || empty($gdeCanton)
			|| !isset(i18n::$cantons[$gdeCanton])
		) {
			$formValues = [
				'birthDate' => $birthDate,
				'street'    => $street,
				'streetNo'  => $streetNo,
				'zip'       => $zip,
				'city'      => $city,
				'gdeId'     => $gdeId,
				'gdeZip'    => $gdeZip,
				'gdeName'   => $gdeName,
				'gdeCanton' => $gdeCanton,
			];
			Core::showError('Invalid form values received: ' . print_r($formValues, true), 400);
		}

		// Prepare update
		$birthDateMysql = sprintf('%s-%s-%s', $birthDateParsed['year'], $birthDateParsed['month'], $birthDateParsed['day']);
		$data           = [
			'birth_date'    => $birthDateMysql,
			'street'        => $street,
			'street_no'     => $streetNo,
			'zip'           => $zip,
			'city'          => $city,
			'country'       => $country,
			'gde_no'        => $gdeId,
			'gde_zip'       => $gdeZip,
			'gde_name'      => $gdeName,
			'gde_canton'    => $gdeCanton,
			'is_step2_done' => 1,
		];

		// Append additional values to $data
		$data = $this->saveStep2PagesUrl($guid, $country, $gdeCanton, $gdeId, $data);

		if ($optinMode = $this->getOptinMode(2)) {
			$optIn            = isset($_REQUEST['is_optin']) && $_REQUEST['is_optin'] ? 1 : 0;
			$optIn            = ($optinMode === 'optOut' || $optinMode === 'optOutChk') ? !$optIn : $optIn;
			$data['is_optin'] = $optIn;
		}

		// Update
		$success = $dbSign->update(
			$data,
			['ID' => $signId,],
			$isEncrypted
		);
		if (!$success) {
			Core::showError('DB update failed: ' . Db::getError(), 500);
		}
		return $data['link_success'];
	}

	/**
	 * @param             $guid
	 * @param string|null $country
	 * @param string      $gdeCanton
	 * @param string      $gdeId
	 * @param array       $data
	 *
	 * @return array
	 */
	protected function saveStep2PagesUrl($guid, string $country, string $gdeCanton, string $gdeId, array $data)
	{
		$abroadRedirect   = Config::getValue('swiss_abroad_redirect');
		$isAbroadRedirect = $abroadRedirect && $country !== i18n::$defaultCountry;
		$isOutsideScope   = false;
		if (($localIniMode = Config::getValue('local_initiative_mode')) !== 'disabled') {
			$isOutsideScope =
				$localIniMode === 'canton' && Config::getValue('local_initiative_canton') !== $gdeCanton
				|| $localIniMode === 'commune' && Config::getValue('local_initiative_commune') !== $gdeId;
		}
		if ($isAbroadRedirect) {
			$successPage              = Strings::getPdfUrl($guid, $abroadRedirect);
			$data['link_success']     = $successPage;
			$data['link_pdf']         = $successPage;
			$data['is_outside_scope'] = ($localIniMode !== 'disabled') ? 1 : 0;
		} elseif ($isOutsideScope) {
			$successPage              = Strings::getPdfUrl($guid, Config::getValue('local_initiative_error_redirect'));
			$data['link_success']     = $successPage;
			$data['link_pdf']         = $successPage;
			$data['is_outside_scope'] = 1;
		} else {
			$successPage          = Strings::getPdfUrl($guid, Config::getValue('use_page_as_success'));
			$data['link_success'] = $successPage;
			$data['link_pdf']     = Strings::getPdfUrl($guid, Config::getValue('use_page_as_mail_link'));
		}

		$linkOptin          = Strings::getPdfUrl($guid, Config::getValue('use_page_as_optin_link'));
		$data['link_optin'] = $linkOptin;
		return $data;
	}

	/**
	 * Show signature sheet partial or return redirect URL
	 *
	 * @param $guid
	 */
	public function step3($guid)
	{
		$dbSign = new DbSignatures();
		$loadedByGuid = false;
		$redirect     = isset($_REQUEST['redirect']) && $_REQUEST['redirect'];
		if ($guid) {
			// User from reminder mail
			$loadedByGuid = true;
			if ($redirect) {
				// Redirect to success page
				$row = $dbSign->getRow(['link_success',], "guid = '" . $guid . "'");
				if (!$row === null) {
					Core::showError('Signature guid ' . $guid . ' not found', 404);
				}
				echo $row->link_success;
				wp_die();
			}
		} else {
			$this->verifyNonce();
			$signId = $this->getSessionVar('signId');

			// Verify 2nd form step is filled and get encryption mode
			$row = $dbSign->getRow(
				['is_step2_done', 'guid', 'is_encrypted', 'link_success',],
				"ID = '" . $signId . "'"
			);
			if ($row === null) {
				Core::showError('Invalid session, signature ID ' . $signId . ' not found', 500);
			}
			$guid = $row->guid;
			if (!$row->is_step2_done) {
				$successPage = $this->saveStep2($signId, $guid, $row->is_encrypted);
			} else {
				$successPage = $row->link_success;
			}
			if ($redirect) {
				// Redirect to success page
				echo $successPage;
				wp_die();
			}
		}

		// Show inline success page
		$this->step3inlineSuccessPage($guid);

		if (!$loadedByGuid) {
			wp_die(); // Die, as this is the response on a AJAX request TODO: recognize AJAX
		}
	}

	/**
	 * @param      $guid
	 */
	protected function step3inlineSuccessPage($guid)
	{
		$dbSign = new DbSignatures();
		// Prepare PDF data
		$row = $dbSign->getRow(
			[
				'ID',
				'birth_date',
				'gde_name',
				'gde_zip',
				'gde_canton',
				'street',
				'street_no',
				'is_step2_done',
				'zip',
				'city',
				'country',
			],
			"guid = '" . $guid . "'"
		);
		if (!$row) {
			Core::showError('Signature with GUID "' . $guid . '" was not found', 404);
		}
		if (!$row->is_step2_done) {
			Core::showError('Signature with GUID "' . $guid . '" can not be edited', 400);
		}
		$signId    = $row->ID;
		$gdeCanton = $row->gde_canton;
		$gdeName   = $row->gde_name;
		$gdeZip    = $row->gde_zip ?: $row->zip;
		$birthDate = date_parse($row->birth_date);
		$street    = $row->street;
		$streetNo  = $row->street_no;
		$zip       = $row->zip;
		$city      = $row->city;
		$country   = strtoupper($row->country);

		// PDF Fields
		$birthDateDay   = $birthDate ? $birthDate['day'] : '';
		$birthDateMonth = $birthDate ? $birthDate['month'] : '';
		$birthDateYear  = $birthDate ? $birthDate['year'] : '';
		if ($country && $country !== i18n::$defaultCountry) {
			$address = [
				'size' => Config::getValue('swiss_abroad_fontsize'),
				'text' => $street . ' ' . $streetNo . ', ' . $country . '-' . $zip . ' ' . $city,
			];
		} else {
			$address = $street . ' ' . $streetNo;
		}
		$fields = $this->formatFields(
			[
				'field_canton'          => strtoupper($gdeCanton),
				'field_commune'         => $gdeName,
				'field_zip'             => $gdeZip,
				'field_birthdate_day'   => str_pad($birthDateDay, 2, '0', STR_PAD_LEFT),
				'field_birthdate_month' => str_pad($birthDateMonth, 2, '0', STR_PAD_LEFT),
				'field_birthdate_year'  => substr($birthDateYear, -2),
				'field_street'          => $address,
			]
		);

		// PDF QR-code
		if (($qrMode = Config::getValue('field_qr_mode')) === 'disabled') {
			$qrData = null;
		} else {
			$shortcode  = Strings::getSerial($signId, $qrMode);
			$qrPosX     = Config::getValueByUserlang('field_qr_img', Config::PART_POS_X);
			$qrPosY     = Config::getValueByUserlang('field_qr_img', Config::PART_POS_Y);
			$qrTextPosX = Config::getValueByUserlang('field_qr_text', Config::PART_POS_X);
			$qrTextPosY = Config::getValueByUserlang('field_qr_text', Config::PART_POS_Y);
			$fontSize   = Config::getValue('fontsize');
			$qrData     = [
				'text'       => (string)$shortcode,
				'x'          => intval($qrPosX),
				'y'          => intval($qrPosY),
				'size'       => intval(Config::getValueByUserlang('field_qr_img_size')),
				'rotate'     => intval(Config::getValueByUserlang('field_qr_img', Config::PART_ROTATION)),
				'textX'      => intval($qrTextPosX),
				'textY'      => intval($qrTextPosY),
				'textRotate' => intval(Config::getValueByUserlang('field_qr_text', Config::PART_ROTATION)),
				'textSize'   => intval($fontSize),
				'textColor'  => $this->textColor,
			];
		}

		// Prepare view variables
		$title     = __('signature_sheet', 'demovox');
		$permalink = Strings::getPdfUrl($guid);
		$pdfUrl    = Config::getValueByUserlang('signature_sheet');
		$fields    = json_encode($fields);
		$qrData    = $qrData ? json_encode($qrData) : null;

		// Render view
		include Infos::getPluginDir() . 'public/partials/sign-3.php';
	}

	private function setSessionVar($name, $value)
	{
		$ret = $_SESSION[$name] = $value;
		return $ret;
	}

	private function getSessionVar($name)
	{
		$value = isset($_SESSION[$name]) ? $_SESSION[$name] : null;
		return $value;
	}

	private function verifyNonce()
	{
		if (!wp_verify_nonce($_REQUEST['nonce'], $this->nonceId)) {
			Core::showError('nonce check failed', 401);
		}
	}

	private function formatFields($fields)
	{
		$fontSize  = Config::getValue('fontsize');
		$textColor = $this->textColor;

		$return = [];
		foreach ($fields as $name => $value) {
			$posX   = Config::getValueByUserlang($name, Config::PART_POS_X);
			$posY   = Config::getValueByUserlang($name, Config::PART_POS_Y);
			$rotate = Config::getValueByUserlang($name, Config::PART_ROTATION);
			if (!$posX && $posX !== 0) {
				continue;
			}
			if (is_array($value)) {
				$text = $value['text'];
				if (isset($value['size'])) {
					$fontSize = $value['size'];
				}
			} else {
				$text = $value;
			}
			$return[] = [
				//'name'     => $name,
				'drawText' => (string)$text,
				'x'        => (int)$posX,
				'y'        => (int)$posY,
				'rotate'   => (int)$rotate,
				'size'     => (int)$fontSize,
				'color'    => (array)$textColor,
			];
		}
		return $return;
	}

	protected function getOptinMode($page)
	{
		$optinMode     = Config::getValue('optin_mode');
		$optinPosition = Config::getValue('optin_position');
		return ($optinMode !== 'disabled' && $optinPosition == $page) ? $optinMode : null;
	}

}