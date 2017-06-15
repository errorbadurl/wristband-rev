<?php

namespace App\Http\Controllers;

use App;
use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\Models\AddOns;
use App\Models\Orders;
use App\Models\Prices;
use App\Models\Sizes;
use App\Models\Styles;
use App\Models\TimeProduction;
use App\Models\TimeShipping;
use App\Wristbands\Classes\ClipartList;
use App\Wristbands\Classes\Colors;
use App\Wristbands\Classes\ColorsList;
use App\Wristbands\Classes\FontList;
use App\Wristbands\Classes\Styles as jsonStyles;
use App\Wristbands\Classes\Sizes as jsonSizes;
use App\Wristbands\Classes\SizeChart;
use App\Wristbands\Classes\ProductGallery;
use Excel;
use File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Input;
use Response;
use Session;
use Storage;

class AdminController extends Controller
{

    protected $pathWB;
    protected $pathAO;
    protected $pathSPD;
    protected $pathSPI;
    protected $pathPD;
    protected $pathImageTemp;
    protected $pathImageOrder;

    public function __construct()
    {
        $this->middleware('admin');

        $this->pathWB = App::make('config')->get('services.filepath.prices.wristband.path');
        $this->pathAO = App::make('config')->get('services.filepath.prices.addon.path');
        $this->pathSPD = App::make('config')->get('services.filepath.prices.shipping_domestic.path');
        $this->pathSPI = App::make('config')->get('services.filepath.prices.shipping_international.path');
        $this->pathPD = App::make('config')->get('services.filepath.prices.production.path');
        $this->pathImageTemp = App::make('config')->get('services.filepath.images.temp.path');
        $this->pathImageOrder = App::make('config')->get('services.filepath.images.order.path');
    }

    public function index()
    {
        return redirect('/admin/prices');
    }

    public function managePrices()
    {
        return view('admin.manage.prices');
    }

    public function manageImages()
    {
        $size = 0;
        $data = [
            'size' => 0
        ];
        foreach(File::allFiles($this->pathImageTemp) as $key => $value) {
            if(!strpos($value, date('Ymd'))) {
                $size += File::size($value->getPathName());
            }
        }
        $data['size'] = $this->formatBytes($size);

        return view('admin.manage.images', $data);
    }

    public function manageOrders()
    {
        return view('admin.manage.orders', []);
    }

    public function manageDiscounts()
    {
        return view('admin.manage.discounts', []);
    }

    public function resetJSON()
    {
        return view('admin.manage.cacheReset');
    }

    public function processResetJSON(Request $request)
    {
        try {

    		$price = new Prices();
            $price->resetAll();

            $sizes = new jsonSizes();
            $sizes->reset();

    		$styles = new jsonStyles();
            $styles->reset();

            $list_clipart = new ClipartList();
            $list_clipart->reset();

    		$colors = new Colors();
            $colors->reset();

    		$list_color = new ColorsList();
            $list_color->reset();

    		$list_font = new FontList();
            $list_font->reset();

            $gallery = new ProductGallery();
            $gallery->reset();

            $sizechart = new SizeChart();
            $sizechart->reset();

            return json_encode([ 'status' => true ]); // Success!
        } catch(\Exception $ex) {
            // Hah! Something went wrong!
            return json_encode([ 'status' => false ]);
        }
        return json_encode([ 'status' => false ]); // Ugh! Nope!
    }

    public function clearTempImages(Request $request)
    {
        try {
            // Check if folder exists.
            if(File::exists($this->pathImageTemp)) {
                // Clean the folder.
                $dirs = File::directories($this->pathImageTemp);
                foreach($dirs as $key => $value) {
                    if($value != $this->pathImageTemp . '\\' .  date('Ymd')) {
                        File::deleteDirectory($value);
                    }
                }
                return json_encode([ 'status' => true ]); // Success!
            }
        } catch(\Exception $ex) {
            // Hah! Something went wrong!
            return json_encode([ 'status' => false ]);
        }
        return json_encode([ 'status' => false ]); // WRONG!!!
    }

    // Wristband Prices --------------------------------------------------------

    public function updatePricesWB(Request $request)
    {
        // First, get files.
        $files = Input::file('files');
        // Check if file exists.
        if($files) {
            // Check if excel file exists.
            if(isset($files[0])) {
                // Clean directory first.
                $this->deletePricesWB($request);
                // Create image name.
                $filename = 'price' . '.' . $files[0]->getClientOriginalExtension();
                // Process image transport.
                $uploadSuccess = $files[0]->move($this->pathWB, $filename);
                // Check if successful.
                if($uploadSuccess) {
                    // Process database seeding.
                    $update_status = $this->updatePricesWBData($request);
                    // Release upload status.
                    return json_encode([ 'status' => $update_status ]);
                }
            }
        }
        return json_encode([ 'status' => false ]);
    }

    public function downloadPricesWB(Request $request)
    {
        switch ($request->ext) {
            case 'csv': // Return .csv format file
                return Response::download('format/prices/wristband.csv', 'price_wristbands.csv');
                break;

            case 'xls': // Return .xls format file
                return Response::download('format/prices/wristband.xls', 'price_wristbands.xls');
                break;

            case 'xlsxx': // Return .xlsx format file
                return Response::download('format/prices/wristband.xlsx', 'price_wristbands.xlsx');
                break;

            default: // Return .csv as default format file
                return Response::download('format/prices/wristband.csv', 'price_wristbands.csv');
                break;
        }
    }

    public function reuploadPricesWB(Request $request)
    {
        // First, get files.
        $files = Input::file('files');
        // Check if file exists.
        if($files) {
            // Check if excel file exists.
            if(isset($files[0])) {
                // Clean directory first.
                $this->deletePricesWB($request);
                // Create image name.
                $filename = 'price' . '.' . $files[0]->getClientOriginalExtension();
                // Process image transport.
                $uploadSuccess = $files[0]->move($this->pathWB, $filename);
                // Check if successful.
                if($uploadSuccess) {
                    // Upload successful.
                    return json_encode([ 'status' => true ]);
                }
            }
        }
        return json_encode([ 'status' => false ]);// Ugh! Nope! Problem...
    }

    public function reprocessPricesWB(Request $request)
    {
        // Reprocess database seeding.
        $update_status = $this->updatePricesWBData($request);
        // Release upload status.
        return json_encode([ 'status' => $update_status ]);
    }

    public function deletePricesWB(Request $request)
    {
        // Check if folder exists.
        if(File::exists($this->pathWB)) {
            // Clean the folder.
            File::cleanDirectory($this->pathWB);
        }
    }

    public function updatePricesWBData(Request $request)
    {
        $count = 0;
        $files = [];
        // Get files
        do{
            $files = File::allFiles($this->pathWB);
            $count++;
        } while ($count < 50 && count($files) < 0);
        // Check if has files
        if(count($files) > 0) {
            try {
                Excel::load($files[0]->getPathname(), function ($reader) {
                    // Create array to contain new price data.
                    $csv = [];
                    // Get available  sizes.
                    $sizes = Sizes::getArrayByCode();
                    // Get available styles.
                    $styles = Styles::getArrayByCode();
                    foreach ($reader->toArray() as $sheet) {
                        if(!isset($sheet['style_code']) || !isset($sheet['size_code'])) {
                            foreach ($sheet as $rowKey => $row) {
                                if($row['style_code'] !== null || $row['size_code'] !== null ) {
                                    foreach ($row as $key => $value) {
                                        if(is_int($key)) {
                                            $csv[] = [
                                                'style_id' => $styles[$row['style_code']],
                                                'size_id' => $sizes[$row['size_code']],
                                                'qty' => $key,
                                                'price' => $value
                                            ];
                                        }
                                    }
                                }
                            }
                        } else {
                            if($sheet['style_code'] !== null || $sheet['size_code'] !== null ) {
                                foreach ($sheet as $key => $value) {
                                    if(is_int($key)) {
                                        $csv[] = [
                                            'style_id' => $styles[$sheet['style_code']],
                                            'size_id' => $sizes[$sheet['size_code']],
                                            'qty' => $key,
                                            'price' => $value
                                        ];
                                    }
                                }
                            }
                        }
                    }
                    if(count($csv) > 0) {
                        Prices::truncatePrice();
                        Prices::insertPrice($csv);
                    }
                });
            } catch (\Exception $e) {
                return false;
            }
            return true;
        }
        return false;
    }

    // Addon Prices ------------------------------------------------------------

    public function updatePricesAO(Request $request)
    {
        // First, get files.
        $files = Input::file('files');
        // Check if file exists.
        if($files) {
            // Check if excel file exists.
            if(isset($files[0])) {
                // Clean directory first.
                $this->deletePricesAO($request);
                // Create image name.
                $filename = 'price' . '.' . $files[0]->getClientOriginalExtension();
                // Process image transport.
                $uploadSuccess = $files[0]->move($this->pathAO, $filename);
                // Check if successful.
                if($uploadSuccess) {
                    // Process database seeding.
                    $update_status = $this->updatePricesAOData($request);
                    // Release upload status.
                    return json_encode([ 'status' => $update_status ]);
                }
            }
        }
        return json_encode([ 'status' => false ]); // Ugh! Nope!
    }

    public function downloadPricesAO(Request $request)
    {
        switch ($request->ext) {
            case 'csv': // Return .csv format file
                return Response::download('format/prices/addon.csv', 'price_addons.csv');
                break;

            case 'xls': // Return .xls format file
                return Response::download('format/prices/addon.xls', 'price_addons.xls');
                break;

            case 'xlsxx': // Return .xlsx format file
                return Response::download('format/prices/addon.xlsx', 'price_addons.xlsx');
                break;

            default: // Return .csv as default format file
                return Response::download('format/prices/addon.csv', 'price_addons.csv');
                break;
        }
    }

    public function reuploadPricesAO(Request $request)
    {
        // First, get files.
        $files = Input::file('files');
        // Check if file exists.
        if($files) {
            // Check if excel file exists.
            if(isset($files[0])) {
                // Clean directory first.
                $this->deletePricesAO($request);
                // Create image name.
                $filename = 'price' . '.' . $files[0]->getClientOriginalExtension();
                // Process image transport.
                $uploadSuccess = $files[0]->move($this->pathAO, $filename);
                // Check if successful.
                if($uploadSuccess) {
                    // Upload successful.
                    return json_encode([ 'status' => true ]);
                }
            }
        }
        return json_encode([ 'status' => false ]);// Ugh! Nope! Problem...
    }

    public function reprocessPricesAO(Request $request)
    {
        // Reprocess database seeding.
        $update_status = $this->updatePricesAOData($request);
        // Release upload status.
        return json_encode([ 'status' => $update_status ]);
    }

    public function deletePricesAO(Request $request)
    {
        // Check if folder exists.
        if(File::exists($this->pathAO)) {
            // Clean the folder.
            File::cleanDirectory($this->pathAO);
        }
    }

    public function updatePricesAOData(Request $request)
    {
        $count = 0;
        $files = [];
        // Get files
        do{
            $files = File::allFiles($this->pathAO);
            $count++;
        } while ($count < 50 && count($files) < 0);
        // Check if has files
        if(count($files) > 0) {
            try {
                Excel::load($files[0]->getPathname(), function ($reader) {
                    // Create array to contain new price data.
                    $csv = [];
                    // Get available addons.
                    $add_ons = AddOns::getArrayByCode();
                    foreach ($reader->toArray() as $sheet) {
                        if(!isset($sheet['code'])) {
                            foreach ($sheet as $rowKey => $row) {
                                if($row['style_code'] !== null || $row['size_code'] !== null ) {
                                    foreach ($row as $key => $value) {
                                        if(is_int($key)) {
                                            $csv[] = [
                                                'add_on_id' => $add_ons[$row['code']],
                                                'qty' => $key,
                                                'price' => $value
                                            ];
                                        }
                                    }
                                }
                            }
                        } else {
                            if($sheet['code'] !== null) {
                                foreach ($sheet as $key => $value) {
                                    if(is_int($key)) {
                                        $csv[] = [
                                            'add_on_id' => $add_ons[$sheet['code']],
                                            'qty' => $key,
                                            'price' => $value
                                        ];
                                    }
                                }
                            }
                        }
                    }
                    if(count($csv) > 0) {
                        Prices::truncateAddOn();
                        Prices::insertAddOn($csv);
                    }
                });
            } catch (\Exception $e) {
                return false;
            }
            return true;
        }
        return false;
    }

    // Shipping Prices (Domestic) ----------------------------------------------

    public function updatePricesSPD(Request $request)
    {
        // First, get files.
        $files = Input::file('files');
        // Check if file exists.
        if($files) {
            // Check if excel file exists.
            if(isset($files[0])) {
                // Clean directory first.
                $this->deletePricesSPD($request);
                // Create image name.
                $filename = 'price' . '.' . $files[0]->getClientOriginalExtension();
                // Process image transport.
                $uploadSuccess = $files[0]->move($this->pathSPD, $filename);
                // Check if successful.
                if($uploadSuccess) {
                    // Process database seeding.
                    $update_status = $this->updatePricesSPDData($request);
                    // Release upload status.
                    return json_encode([ 'status' => $update_status ]);
                }
            }
        }
        return json_encode([ 'status' => false ]); // Ugh! Nope!
    }

    public function downloadPricesSPD(Request $request)
    {
        switch ($request->ext) {
            case 'xls': // Return .xls format file
                return Response::download('format/prices/price_shipping_domestic.xls', 'price_shipping_domestic.xls');
                break;

            case 'xlsxx': // Return .xlsx format file
                return Response::download('format/prices/price_shipping_domestic.xlsx', 'price_shipping_domestic.xlsx');
                break;

            default: // Return .csv as default format file
                return Response::download('format/prices/price_shipping_domestic.xls', 'price_shipping_domestic.xls');
                break;
        }
    }

    public function reuploadPricesSPD(Request $request)
    {
        // First, get files.
        $files = Input::file('files');
        // Check if file exists.
        if($files) {
            // Check if excel file exists.
            if(isset($files[0])) {
                // Clean directory first.
                $this->deletePricesSPD($request);
                // Create image name.
                $filename = 'price' . '.' . $files[0]->getClientOriginalExtension();
                // Process image transport.
                $uploadSuccess = $files[0]->move($this->pathSPD, $filename);
                // Check if successful.
                if($uploadSuccess) {
                    // Upload successful.
                    return json_encode([ 'status' => true ]);
                }
            }
        }
        return json_encode([ 'status' => false ]);// Ugh! Nope! Problem...
    }

    public function reprocessPricesSPD(Request $request)
    {
        // Reprocess database seeding.
        $update_status = $this->updatePricesSPDData($request);
        // Release upload status.
        return json_encode([ 'status' => $update_status ]);
    }

    public function deletePricesSPD(Request $request)
    {
        // Check if folder exists.
        if(File::exists($this->pathSPD)) {
            // Clean the folder.
            File::cleanDirectory($this->pathSPD);
        }
    }

    public function updatePricesSPDData(Request $request)
    {
        $count = 0;
        $files = [];
        // Get files
        do{
            $files = File::allFiles($this->pathSPD);
            $count++;
        } while ($count < 50 && count($files) < 0);
        // Check if has files
        if(count($files) > 0) {
            try {
                Excel::load($files[0]->getPathname(), function ($reader) {
                    // Create array to contain new price data.
                    $csv = [];
                    // Get available  sizes.
                    $sizes = Sizes::getArrayByCode();
                    // Get available styles.
                    $styles = Styles::getArrayByCode();
                    foreach ($reader->toArray() as $sheet) {
                        foreach ($sheet as $rowKey => $row) {
                            if($row['style_code'] !== null || $row['size_code'] !== null ) {
                                foreach ($row as $key => $value) {
                                    if($this->contains('_days', $key)) {
                                        if(is_int($value) || is_float($value)) {
                                            if($value > 0) {
                                                $csv[] = [
                                                    'style_id' => $styles[$row['style_code']],
                                                    'size_id' => $sizes[$row['size_code']],
                                                    'qty' => $row['quantity'],
                                                    'price' => $value,
                                                    'days' => str_replace('_days', '', $key),
                                                    'type' => 0,
                                                ];
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                    if(count($csv) > 0) {
                        TimeShipping::deleteShippingDomestic();
                        TimeShipping::insertShipping($csv);
                    }
                });
            } catch (\Exception $e) {
                return false;
            }
            return true;
        }
        return false;
    }

    // Shipping Prices (International) -----------------------------------------

    public function updatePricesSPI(Request $request)
    {
        // First, get files.
        $files = Input::file('files');
        // Check if file exists.
        if($files) {
            // Check if excel file exists.
            if(isset($files[0])) {
                // Clean directory first.
                $this->deletePricesSPI($request);
                // Create image name.
                $filename = 'price' . '.' . $files[0]->getClientOriginalExtension();
                // Process image transport.
                $uploadSuccess = $files[0]->move($this->pathSPI, $filename);
                // Check if successful.
                if($uploadSuccess) {
                    // Process database seeding.
                    $update_status = $this->updatePricesSPIData($request);
                    // Release upload status.
                    return json_encode([ 'status' => $update_status ]);
                }
            }
        }
        return json_encode([ 'status' => false ]); // Ugh! Nope!
    }

    public function downloadPricesSPI(Request $request)
    {
        switch ($request->ext) {
            case 'xls': // Return .xls format file
                return Response::download('format/prices/price_shipping_international.xls', 'price_shipping_international.xls');
                break;

            case 'xlsxx': // Return .xlsx format file
                return Response::download('format/prices/price_shipping_international.xlsx', 'price_shipping_international.xlsx');
                break;

            default: // Return .csv as default format file
                return Response::download('format/prices/price_shipping_international.xls', 'price_shipping_international.xls');
                break;
        }
    }

    public function reuploadPricesSPI(Request $request)
    {
        // First, get files.
        $files = Input::file('files');
        // Check if file exists.
        if($files) {
            // Check if excel file exists.
            if(isset($files[0])) {
                // Clean directory first.
                $this->deletePricesSPI($request);
                // Create image name.
                $filename = 'price' . '.' . $files[0]->getClientOriginalExtension();
                // Process image transport.
                $uploadSuccess = $files[0]->move($this->pathSPI, $filename);
                // Check if successful.
                if($uploadSuccess) {
                    // Upload successful.
                    return json_encode([ 'status' => true ]);
                }
            }
        }
        return json_encode([ 'status' => false ]);// Ugh! Nope! Problem...
    }

    public function reprocessPricesSPI(Request $request)
    {
        // Reprocess database seeding.
        $update_status = $this->updatePricesSPIData($request);
        // Release upload status.
        return json_encode([ 'status' => $update_status ]);
    }

    public function deletePricesSPI(Request $request)
    {
        // Check if folder exists.
        if(File::exists($this->pathSPI)) {
            // Clean the folder.
            File::cleanDirectory($this->pathSPI);
        }
    }

    public function updatePricesSPIData(Request $request)
    {
        $count = 0;
        $files = [];
        // Get files
        do{
            $files = File::allFiles($this->pathSPI);
            $count++;
        } while ($count < 50 && count($files) < 0);
        // Check if has files
        if(count($files) > 0) {
            try {
                Excel::load($files[0]->getPathname(), function ($reader) {
                    // Create array to contain new price data.
                    $csv = [];
                    // Get available  sizes.
                    $sizes = Sizes::getArrayByCode();
                    // Get available styles.
                    $styles = Styles::getArrayByCode();
                    foreach ($reader->toArray() as $sheet) {
                        foreach ($sheet as $rowKey => $row) {
                            if($row['style_code'] !== null || $row['size_code'] !== null ) {
                                foreach ($row as $key => $value) {
                                    if($this->contains('_days', $key)) {
                                        if(is_int($value) || is_float($value)) {
                                            if($value > 0) {
                                                $csv[] = [
                                                    'style_id' => $styles[$row['style_code']],
                                                    'size_id' => $sizes[$row['size_code']],
                                                    'qty' => $row['quantity'],
                                                    'price' => $value,
                                                    'days' => str_replace('_days', '', $key),
                                                    'type' => 1,
                                                ];
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                    if(count($csv) > 0) {
                        TimeShipping::deleteShippingInternational();
                        TimeShipping::insertShipping($csv);
                    }
                });
            } catch (\Exception $e) {
                return false;
            }
            return true;
        }
        return false;
    }

    // Production Prices -------------------------------------------------------

    public function updatePricesPD(Request $request)
    {
        // First, get files.
        $files = Input::file('files');
        // Check if file exists.
        if($files) {
            // Check if excel file exists.
            if(isset($files[0])) {
                // Clean directory first.
                $this->deletePricesPD($request);
                // Create image name.
                $filename = 'price' . '.' . $files[0]->getClientOriginalExtension();
                // Process image transport.
                $uploadSuccess = $files[0]->move($this->pathPD, $filename);
                // Check if successful.
                if($uploadSuccess) {
                    // Process database seeding.
                    $update_status = $this->updatePricesPDData($request);
                    // Release upload status.
                    return json_encode([ 'status' => $update_status ]);
                }
            }
        }
        return json_encode([ 'status' => false ]); // Ugh! Nope!
    }

    public function downloadPricesPD(Request $request)
    {
        switch ($request->ext) {
            case 'xls': // Return .xls format file
                return Response::download('format/prices/price_production.xls', 'price_production.xls');
                break;

            case 'xlsxx': // Return .xlsx format file
                return Response::download('format/prices/price_production.xlsx', 'price_production.xlsx');
                break;

            default: // Return .csv as default format file
                return Response::download('format/prices/price_production.xls', 'price_production.xls');
                break;
        }
    }

    public function reuploadPricesPD(Request $request)
    {
        // First, get files.
        $files = Input::file('files');
        // Check if file exists.
        if($files) {
            // Check if excel file exists.
            if(isset($files[0])) {
                // Clean directory first.
                $this->deletePricesPD($request);
                // Create image name.
                $filename = 'price' . '.' . $files[0]->getClientOriginalExtension();
                // Process image transport.
                $uploadSuccess = $files[0]->move($this->pathPD, $filename);
                // Check if successful.
                if($uploadSuccess) {
                    // Upload successful.
                    return json_encode([ 'status' => true ]);
                }
            }
        }
        return json_encode([ 'status' => false ]);// Ugh! Nope! Problem...
    }

    public function reprocessPricesPD(Request $request)
    {
        // Reprocess database seeding.
        $update_status = $this->updatePricesPDData($request);
        // Release upload status.
        return json_encode([ 'status' => $update_status ]);
    }

    public function deletePricesPD(Request $request)
    {
        // Check if folder exists.
        if(File::exists($this->pathPD)) {
            // Clean the folder.
            File::cleanDirectory($this->pathPD);
        }
    }

    public function updatePricesPDData(Request $request)
    {
        $count = 0;
        $files = [];
        // Get files
        do{
            $files = File::allFiles($this->pathPD);
            $count++;
        } while ($count < 50 && count($files) < 0);
        // Check if has files
        if(count($files) > 0) {
            try {
                Excel::load($files[0]->getPathname(), function ($reader) {
                    // Create array to contain new price data.
                    $csv = [];
                    // Get available  sizes.
                    $sizes = Sizes::getArrayByCode();
                    // Get available styles.
                    $styles = Styles::getArrayByCode();
                    foreach ($reader->toArray() as $sheet) {
                        foreach ($sheet as $rowKey => $row) {
                            if($row['style_code'] !== null || $row['size_code'] !== null ) {
                                foreach ($row as $key => $value) {
                                    if($this->contains('_days', $key)) {
                                        if(is_int($value) || is_float($value)) {
                                            if($value > 0) {
                                                $csv[] = [
                                                    'style_id' => $styles[$row['style_code']],
                                                    'size_id' => $sizes[$row['size_code']],
                                                    'qty' => $row['quantity'],
                                                    'price' => $value,
                                                    'days' => str_replace('_days', '', $key),
                                                ];
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                    if(count($csv) > 0) {
                        TimeProduction::truncateProduction();
                        TimeProduction::insertProduction($csv);
                    }
                });
            } catch (\Exception $e) {
                return false;
            }
            return true;
        }
        return false;
    }

    // Orders ------------------------------------------------------------------

    public function getOrders(Request $request)
    {
        switch ($request->order[0]['column']) {
            case '0':
                $order_col = "ID";
                break;
            case '2':
                $order_col = "PaymentMethod";
                break;
            case '3':
                $order_col = "PaidDate";
                break;
            case '4':
                $order_col = "AuthorizeTransID";
                break;
            case '5':
                $order_col = "PaypalEmail";
                break;
            case '6':
                $order_col = "FirstName";
                break;
            case '7':
                $order_col = "LastName";
                break;
            case '8':
                $order_col = "Address";
                break;
            case '9':
                $order_col = "Address2";
                break;
            case '10':
                $order_col = "City";
                break;
            case '11':
                $order_col = "State";
                break;
            case '12':
                $order_col = "ZipCode";
                break;
            case '13':
                $order_col = "Country";
                break;
            case '14':
                $order_col = "Phone";
                break;
            case '15':
                $order_col = "ProductionCharge";
                break;
            case '16':
                $order_col = "DaysProduction";
                break;
            case '17':
                $order_col = "DeliveryCharge";
                break;
            case '18':
                $order_col = "DaysDelivery";
                break;
            case '19':
                $order_col = "DiscountCode";
                break;
            case '20':
                $order_col = "DiscountPercent";
                break;
            case '21':
                $order_col = "ShipFirstName";
                break;
            case '22':
                $order_col = "ShipLastName";
                break;
            case '23':
                $order_col = "ShipAddress";
                break;
            case '24':
                $order_col = "ShipAddress2";
                break;
            case '25':
                $order_col = "ShipCity";
                break;
            case '26':
                $order_col = "ShipState";
                break;
            case '27':
                $order_col = "ShipZipCode";
                break;
            case '28':
                $order_col = "ShipCountry";
                break;
            case '29':
                $order_col = "IPAddress";
                break;
            default:
                $order_col = "ID";
                break;
        }

        $data = [];
        $orders = new Orders();
        $orders = $orders->getDatatables(trim($request->search['value']), $request->start, $request->length, $order_col, $request->order[0]['dir']);
        
        foreach ($orders['data'] as $key => $value) {
            if ($value->PaymentMethod == "paypal") {
                $paymentMethod = "<span class='text-info'><i class='fa fa-paypal'></i> PayPal</span>";
            } else if ($value->PaymentMethod == "authnet") {
                $paymentMethod = "<span class='text-warning'><i class='fa fa-credit-card'></i> Auth.Net</span>";
            } else {
                $paymentMethod = "-";
            };
            
            $data[] = [
                $value->ID,
                ($value->Paid) ? "<i class='fa fa-check text-success'></i>" : "<i class='fa fa-times text-danger'></i>",
                $paymentMethod,
                date('Y-m-d', strtotime($value->PaidDate)),
                ($value->AuthorizeTransID) ? $value->AuthorizeTransID : "-",
                ($value->PaypalEmail) ? $value->PaypalEmail : "-",
                ucwords(strtolower($value->FirstName)),
                ucwords(strtolower($value->LastName)),
                $value->Address,
                $value->Address2,
                $value->City,
                $value->State,
                $value->ZipCode,
                $value->Country,
                $value->Phone,
                "$".$value->ProductionCharge,
                $value->DaysProduction." Days",
                "$".$value->DeliveryCharge,
                $value->DaysDelivery." Days",
                "<em style='text-transform: uppercase;'>".$value->DiscountCode."</em>",
                $value->DiscountPercent,
                ucwords(strtolower($value->ShipFirstName)),
                ucwords(strtolower($value->ShipLastName)),
                $value->ShipAddress,
                $value->ShipAddress2,
                $value->ShipCity,
                $value->ShipState,
                $value->ShipZipCode,
                $value->ShipCountry,
                "<i>".$value->IPAddress."</i>",
                "<button class='btn btn-danger'>Delete</button></i>",
            ];
        }
		$output = [
    		"draw"				=> $request->draw,
    		"data"				=> $data,
    		"recordsTotal"		=> $orders['total'],
    		"recordsFiltered"	=> count($orders['data'])
        ];
		echo json_encode($output);
        exit;
    }

    // Miscellaneous -----------------------------------------------------------

    public function contains($needle, $haystack)
    {
        return strpos($haystack, $needle) !== false;
    }

    public function formatBytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        // Uncomment one of the following alternatives
        $bytes /= pow(1024, $pow);
        // $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

}
