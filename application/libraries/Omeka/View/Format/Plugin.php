<?php 
/**
* Process all the feeds that aren't built in (these are to be handled by plugins)
*/
class Omeka_View_Format_Plugin extends Omeka_View_Format_Abstract
{
	/**
	 * Since this doesn't have a default output type, it has to store it in the options
	 *
	 * @return void
	 **/
	protected function getFormat()
	{
		return $this->options['format'];
	}
	
	protected function uriMatchesCurrent($uri)
	{		
		$path = $this->getView()->getRequest()->getPathInfo();
		
		//Trim leading/trailing slashes
		$path = trim($path, " \n/");
		
		$uri = trim($uri, " \n/");
		
		return ($path == $uri);
	}
	
	protected function getMimeType($feed_options)
	{
		if( $feed_options['mime_type']) {
			return $feed_options['mime_type'];
		}
		elseif($filename = $feed_options['feed_filename']) {
			return @mime_content_type($filename);
		}
	}
	
	protected function renderFilename($feed_options)
	{
		if(($filename = $feed_options['feed_filename']) and file_exists($filename) and is_readable($filename)) {

			//Render the file directly
			return file_get_contents($filename);
		}		
	}
	
	/**
	 * If we passed 'handler_class' => 'MyDataFeedClass' to the data feed options, it receives 
	 *
	 * @return void
	 **/
	protected function renderCustomClass($feed_options)
	{
		if(($handler_class = $feed_options['handler_class']) and class_exists($handler_class)) {
			//Merge the options passed from the View and the options received from the feed, pass to the handler
			$handler = new $handler_class($handler_class, array_merge($this->options, $feed_options));
			
			if(!($handler instanceof Omeka_View_Format_Abstract)) {
				throw new Omeka_View_Format_Exception( $handler_class . ' must be derived from Omeka_View_Format_Abstract!' );
			}
			
			return $handler->render();
		}
	}
	
	/**
	 * Plugin writer can pass a 'render_callback' option that would allow 
	 * plugin writer to render a set of records retrieved by Omeka
	 *
	 * @return void
	 **/
	protected function renderCallback($feed_options)
	{
		if($callback = $feed_options['render_callback']) {

			if($record = $this->getRecord()) {
				$recordset = array($record);
			}
			elseif( !($recordset = $this->getRecordset()) ) {
				throw new Omeka_View_Format_Exception( "There is no recordset to render for the given callback!" );
			}
			
			return call_user_func_array($callback, array($recordset));
		}
	}
	
	protected function getFeedFromPluginBroker()
	{
		$broker = get_plugin_broker();
		
		$format = $this->getFormat();
	
		if($broker->hasFeed($format)) {
			
			$feeds = $broker->getFeed($format);

			//There may be multiple feeds so try to discover which one to render in a given situation
			if(count($feeds)) {
			
				//Check each feed for this format to see whether the URI specified is the same
				foreach ($feeds as $feed) {
					$feed_uri = $feed['access_uri'];
					if($this->uriMatchesCurrent($feed_uri)) {
						return $feed;
					}
				}
				
				//We didn't find the feed we needed
				throw new Omeka_View_Format_Exception( "There are multiple feeds available for the '$format' output type but none of them respond when accessed via the current URL" );
			
			}
		}
		else {
			throw new Omeka_View_Format_Exception( "Feed does not exist for '$format' data format!" );
		}		
	}
	
	protected function _render()
	{
		//Check the plugins to see what feeds they have available
		$feed = $this->getFeedFromPluginBroker();
		
		//Gotta pull the mime_type of the feed and set the content type to it
		if($mime_type = $this->getMimeType($feed)) {
			$this->getView()->getResponse()->setHeader('Content-Type', $mime_type);
		}
		
		//We have 3 different ways of rendering output via the plugins
		$output_strategies = array('renderCustomClass', 'renderFilename', 'renderCallback');
		
		foreach ($output_strategies as $func) {
			if($output = $this->$func($feed)) {
				return $output;
			}
		}
		
		throw new Exception( 'Unable to render the plugin feed!' );
	}
}
 
?>
