<?php
/** Correct the WordPress stubs' void return for core's class-name generator. */
class WP_HTML_Tag_Processor {
	/** @return Generator<int,string,void,void> */
	public function class_list() {}
}
