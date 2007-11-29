<?php 
require_once '../paths.php';
/* Steps for installation:
 * 1) Fill out your db.ini file by hand
 * 2) This form detects a database connection, builds the tables
 * 3) Submit this form with all the relevant settings, they are saved to DB 
 */
require_once 'Zend/Config/Ini.php';
require_once 'plugins.php';
require_once 'globals.php';

require_once 'Omeka.php';
spl_autoload_register(array('Omeka', 'autoload'));

try {
	//Check for the config file
	$config_file = CONFIG_DIR . DIRECTORY_SEPARATOR . 'db.ini';
	if (!file_exists($config_file)) {
		throw new Exception('Your Omeka database configuration file is missing.');
	}
	if (!is_readable($config_file)) {
		throw new Exception('Your Omeka database configuration file cannot be read by the application.');
	}

	$config = new Zend_Config_Ini($config_file, null);

	Zend_Registry::set('config_ini', $config);

	$db_config = $config->database->toArray();

	//Fail on improperly configured db.ini file
	if (!isset($db_config['host']) or ($db_config['host'] == 'XXXXXXX')) {
		throw new Exception('Your Omeka database configuration file has not been set up properly.');
	}

	//Create the DSN
	$dsn = 'mysql:host='.$db_config['host'].';dbname='.$db_config['name'];
	if(isset($db_config['port'])) {
		$dsn .= 'port='.$db_config['port'].';';
	}	

	//PDO Connection
	//@todo Add "port" option to db.ini and all PDO connections within the app
	$dbh = new PDO($dsn, $db_config['username'], $db_config['password']);
	if (!$dbh instanceof PDO) {
		throw new Exception('<h2>No database connection could be created</h2>');
	}

	$db = new Omeka_Db($dbh, $db_config['prefix']);
	
	Zend_Registry::set('db', $db);
	
	//Build the database if necessary
	$res = $dbh->query("SHOW TABLES");
	$tables = $res->fetchAll();
	
	if(empty($tables)) {
		// Build the tables explicitly
		include 'install.sql.php';
		$db->execBlock($install_sql);
	}

	//Check if the options table is filled (if so, Omeka already set up so die)
	require_once 'Option.php';
	$options = $db->getTable('Option')->findAll();
	if (count($options)) {
		throw new Exception('<h1>Omeka Already Installed</h1><p>It looks like Omeka has already been installed. You can remove the &#8220;install&#8221; directory for security reasons.</p>');
	}
	
	// Use the "which" command to auto-detect the path to ImageMagick;
	// redirect std error to where std input goes, which is nowhere
	// see http://www.unix.org.ua/orelly/unix/upt/ch45_21.htm
	$output = shell_exec('which convert 2>&0');
	$path_to_convert = ($output !== NULL) ? trim($output) : FALSE;
	
} catch (Exception $e) {
	die($e->getMessage() . '<p>Please refer to <a href="http://omeka.org/codex/">Omeka documentation</a> for help.</p>');
}



$display_form = true;

//Try to actually install the thing
if (isset($_REQUEST['install_submit'])) {
	
	try {
		//Validate the FORM POST
		if(get_magic_quotes_gpc()) {
			$_POST = stripslashes_deep($_POST);
		}	
				
		$length_validator = new Zend_Validate_StringLength(4, 30);
		
		$validation = array(
					'administrator_email' => "EmailAddress",
					'thumbnail_constraint' => "Digits",
					'fullsize_constraint' => "Digits",
					'square_thumbnail_constraint' => "Digits",
					'username' => array('Alnum', $length_validator),		//At least 4 characters, _ . alphanumeric allowed
					'password' => $length_validator);				//At least 4 characters (all allowed)
		
		$filter = new Zend_Filter_Input(null, $validation, $_POST);
		
		//We got some errors
		if($filter->hasInvalid()) {
			$wrong = $filter->getInvalid();
			
			$msg = '';
			
			foreach ($wrong as $field => $m) {
				$explanation = array_pop($m);
				$msg .= "$field: $explanation.\n";
			}
			throw new Exception( $msg );
		}
		
		// Create the default user
		require_once 'User.php';
		require_once 'Person.php';
		
		$userTable = $db->User;
		$entityTable = $db->Entity;
		
		$entitySql = "INSERT INTO $entityTable (type, email, first_name, last_name) VALUES (?, ?, ?, ?)";
		$db->exec($entitySql, array("Person", $_POST['super_email'], 'Super', 'User'));
		
		$userSql = "INSERT INTO $userTable (username, password, active, role, entity_id) VALUES (?, SHA1(?), 1, 'super', LAST_INSERT_ID())";
		$db->exec($userSql, array($_POST['username'], $_POST['password']));
		
	
		// Namespace for the authentication session (to prevent clashes on shared servers)
		$optionTable = $db->Option;
		
		$optionSql = "INSERT INTO $optionTable (name, value) VALUES (?,?)";
		$db->exec($optionSql, array('auth_prefix', md5(mt_rand())));
		$db->exec($optionSql, array('migration', OMEKA_MIGRATION));
		
		// Add the settings to the db
		$settings = array('administrator_email', 'copyright', 'site_title', 'author', 'description', 'thumbnail_constraint', 'square_thumbnail_constraint', 'fullsize_constraint', 'path_to_convert');
		foreach ($settings as $v) {
			$db->exec($optionSql, array($v, $_POST[$v]));
		}
		
		$db->exec($optionSql, array('admin_theme', 'default'));
		$db->exec($optionSql, array('public_theme', 'default'));

		echo '<div id="intro">';
		echo '<h1>All Finished!</h1>';
		echo '<p>Omeka&#8217;s database is setup and you are ready to roll. <a href="'.dirname($_SERVER['REQUEST_URI']).'">Check out your site!</a></p>';
		echo '</div>';
		$display_form = false;

	} catch(Exception $e) {
		$error = $e->getMessage();
//		echo $e->getTraceAsString();
		$display_form = true;
	}
} 
?>
