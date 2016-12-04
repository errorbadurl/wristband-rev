<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Input;
use Session;
use File;

class UploadController extends Controller
{

	public function index(Request $request)
	{
		// do something
	}

	public function uploadTemp(Request $request)
	{
        // First, get files.
        $files = Input::file('files');
        // Check if file exists.
        if($files) {
            // Check if first image exists.
            if(isset($files[0])) {
                // Create image name.
                $filename = $request->name . '.' . $files[0]->getClientOriginalExtension();
                // Set destination path by date for easier cleanup.
                $y = date('Y');
                $m = date('m');
                $d = date('d');
                $destinationPath = 'uploads/temp/'.$y.'/'.$m.'/'.$d.'/'.Session::getId();
                // Process image transport.
                $uploadSuccess = $files[0]->move($destinationPath, $filename);
                // Check if successful.
                if($uploadSuccess) {
                    return json_encode([ 'status' => true, 'path' => $destinationPath.'/'.$filename ]);
                }
            }
        }
        // Return false if nothing happened here. :(
        return json_encode([ 'status' => false, 'path' => '' ]);
	}

    public function deleteTemp($directory)
    {
        if(File::deleteDirectory($directory)) {
            return true;
        } else {
            return false;
        }
    }

    public function cleanupPast($date)
    {
        // Not functional. Yet.

        // if(!File::exists($path)) {
        //     // path does not exist
        // }
    }

}
