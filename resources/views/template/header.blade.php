
<header>
    <nav class="navbar navbar-inverse navbar-default">
		<div class="container">
			<div id="logo">
				<a class="navbar-brand" href="/"><img src="assets/images/src/prom_logo.jpg"></a>
			</div>
			<div class="header-right-box">
    			<!-- <div id="google_trust" class="pull-right">
    			</div> -->
    			<div class="search pull-right">
                    <div id="hour-img">Monday - Friday | 11am - 8pm EST</div>
                    <span class="span-search"><i class="fa fa-search"></i> 1-800-989-0440</span>
                    <p><span class="fa-text">sales@promotionalwristband.com</span></p>
    			</div>
                <style>
                    .button-wrapper + .tooltip > .tooltip-inner { color: #04adb7; font-family: 'Varela Round', sans-serif!important; font-size: 14px; padding: 10px 15px; }
                    .button-wrapper + .tooltip > .tooltip-arrow { }
                </style>
                <a href="/cart" class="button-wrapper pull-right" data-toggle="tooltip" data-placement="left" title="VIEW CART">
                    <span class="glyphicon glyphicon-shopping-cart"></span><span class="items label label-default">{{ (Session::has('_cart')) ? count(Session::get('_cart')) : "0" }}</span>
                </a>
				<div class="clearfix"></div>
			</div>
			<div class="clearfix"></div>
		</div>
    </nav>

	<div class="container">
		<section class="site-primary-navigation">
			<div class="navbar-header">
			  <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#navbar" aria-expanded="false" aria-controls="navbar">
				<span class="sr-only">Toggle navigation</span>
				<span class="icon-bar"></span>
				<span class="icon-bar"></span>
				<span class="icon-bar"></span>
			  </button>
				<div class="clearfix"></div>
			</div>
			<div id="navbar" class="navbar-collapse collapse">

					<div class="primary-navigation">
						<ul id="menu-header-menu" class="menu-item menu">
							<li class="menu-item menu-item-type-post_type current-menu-item page_item"><a href="index.php">Home</a></li>
							<li class="menu-item menu-item-type-post_type"><a href="order.php">Order Now</a></li>
							<li class="menu-item menu-item-type-post_type"><a href="price.php">Prices</a></li>
							<li class="dropdown menu-item menu-item-type-post_type"><a id="prod-main" data-toggle="dropdown" class="dropdown-toggle">Products</a>
								<ul class="dropdown-menu">
									<li><a href="product-printed.php">Printed</a></li>
									<li><a href="product-debossed.php">Debossed</a></li>
									<li><a href="product-ink-injected.php">Ink Injected</a></li>
									<li><a href="product-embossed.php">Embossed</a></li>
									<li><a href="product-dual-layer.php">Dual Layer</a></li>
									<li><a href="product-embossed-printed.php">Embossed Printed</a></li>
									<li><a href="product-figured.php">Figured</a></li>
									<li><a href="product-blank.php">Blank</a></li>
								</ul>
							</li>
							<li class="dropdown menu-item menu-item menu-item-type-post_type"><a id="prod-main2" href="#" data-toggle="dropdown" class="dropdown-toggle">Wristband Options</a>
								<ul class="dropdown-menu">
									<li><a href="fonts.php">Fonts</a></li>
									<li><a href="cliparts.php">Cliparts</a></li>
									<li><a href="colors.php">Color Chart</a></li>
									<li><a href="sizes.php">Sizes</a></li>
								</ul>


							</li>
							<li class="menu-item menu-item-type-post_type"><a href="gallery.php">Photo Gallery</a></li>
							<li class="menu-item menu-item-type-post_type"><a href="contact-us.php">Contact Us</a></li>
							<li class="menu-item menu-item-type-post_type"><a class="live-chat" href="#">Live Chat</a>
								<!-- <script type='text/javascript' data-cfasync='false'>window.purechatApi = { l: [], t: [], on: function () { this.l.push(arguments); } }; (function () { var done = false; var script = document.createElement('script'); script.async = true; script.type = 'text/javascript'; script.src = 'https://app.purechat.com/VisitorWidget/WidgetScript'; document.getElementsByTagName('HEAD').item(0).appendChild(script); script.onreadystatechange = script.onload = function (e) { if (!done && (!this.readyState || this.readyState == 'loaded' || this.readyState == 'complete')) { var w = new PCWidget({c: 'ff8c4d2b-032b-4f3f-9c29-8411120648ad', f: true }); done = f; } }; })();</script> -->
							</li>
								<div class="clearfix"></div>
						</ul>
					</div>

			</div><!--/.navbar-collapse -->
		</section>

		<!--.Banner Slideshow -->
		 <?php
			/*$homepage = "/promotional/homepage.php";*/
			$homepage = "/dev/homepage.php";
			$currentpage = $_SERVER['REQUEST_URI'];
			if($homepage==$currentpage) {
		?>

			<div class="banner">
				<div id="slideshow">
					<div id="slider-images" style="display: block;">
						<img src="assets/images/src/banner1.jpg">
					</div>
					<div id="slider-images" style="display: none;">
						<img src="assets/images/src/banner3.jpg">
					</div>
					<div id="slider-images" style="display: none;">
						<img src="assets/images/src/banner2.jpg">
					</div>
				</div>
					<div class=""></div>
			</div>

		   <?php } ?>

			<!--/.Banner Slideshow -->
			<div class="timer-area">
			<span class="text-banner">Order 100 wristbands or more & Get 100 Free Wristbands and 10 Keychains!  Time remaining: </span>
			<span id="countdown2"></span>
			<span id="order-now"><a href="order.php">Order Now</a></span>
			</div>
    </div>
</header>
